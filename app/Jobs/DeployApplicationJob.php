<?php

namespace App\Jobs;

use App\Actions\CoolifyTask\RunRemoteProcess;
use App\Data\CoolifyTaskArgs;
use App\Enums\ActivityTypes;
use App\Models\Application;
use App\Models\InstanceSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Yaml\Yaml;
use Illuminate\Support\Str;
use Spatie\Url\Url;

class DeployApplicationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $application;
    protected $destination;
    protected $source;
    protected Activity $activity;
    protected string $git_commit;
    protected string $workdir;
    protected string $docker_compose;
    protected $build_args;
    protected $env_args;
    public static int $batch_counter = 0;
    public $timeout = 3600;
    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $deployment_uuid,
        public string $application_uuid,
        public bool $force_rebuild = false,
    ) {

        $this->application = Application::query()
            ->where('uuid', $this->application_uuid)
            ->firstOrFail();
        $this->destination = $this->application->destination->getMorphClass()::where('id', $this->application->destination->id)->first();

        $server = $this->destination->server;

        $private_key_location = savePrivateKeyForServer($server);

        $remoteProcessArgs = new CoolifyTaskArgs(
            server_ip: $server->ip,
            private_key_location: $private_key_location,
            command: 'overwritten-later',
            port: $server->port,
            user: $server->user,
            type: ActivityTypes::DEPLOYMENT->value,
            type_uuid: $this->deployment_uuid,
        );

        $this->activity = activity()
            ->performedOn($this->application)
            ->withProperties($remoteProcessArgs->toArray())
            ->event(ActivityTypes::DEPLOYMENT->value)
            ->log("[]");
    }
    protected function stopRunningContainer()
    {
        $this->executeNow([
            "echo -n 'Removing old instance... '",
            $this->execute_in_builder("docker rm -f {$this->application->uuid} >/dev/null 2>&1"),
            "echo 'Done.'",
            "echo -n 'Starting your application... '",
        ]);
    }
    protected function startByComposeFile()
    {
        $this->executeNow([
            $this->execute_in_builder("docker compose --project-directory {$this->workdir} up -d >/dev/null"),
        ], isDebuggable: true);
        $this->executeNow([
            "echo 'Done. 🎉'",
        ], isFinished: true);
    }
    protected function generateComposeFile()
    {
        $this->docker_compose = $this->generate_docker_compose();
        $docker_compose_base64 = base64_encode($this->docker_compose);
        $this->executeNow([
            $this->execute_in_builder("echo '{$docker_compose_base64}' | base64 -d > {$this->workdir}/docker-compose.yml")
        ], hideFromOutput: true);
    }
    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $coolify_instance_settings = InstanceSettings::get();
            if ($this->application->deploymentType() === 'source') {
                $this->source = $this->application->source->getMorphClass()::where('id', $this->application->source->id)->first();
            }

            // Get Wildcard Domain
            $project_wildcard_domain = data_get($this->application, 'environment.project.settings.wildcard_domain');
            $global_wildcard_domain = data_get($coolify_instance_settings, 'wildcard_domain');
            $wildcard_domain = $project_wildcard_domain ?? $global_wildcard_domain ?? null;

            // Set wildcard domain
            if (!$this->application->fqdn && $wildcard_domain) {
                $this->application->fqdn = 'http://' . $this->application->uuid . '.' . $wildcard_domain;
                $this->application->save();
            }
            $this->workdir = "/artifacts/{$this->deployment_uuid}";

            // Pull builder image
            $this->executeNow([
                "echo 'Starting deployment of {$this->application->git_repository}:{$this->application->git_branch}...'",
                "echo -n 'Pulling latest version of the builder image (ghcr.io/coollabsio/coolify-builder)... '",
            ]);

            $this->executeNow([
                "docker run --pull=always -d --name {$this->deployment_uuid} --rm -v /var/run/docker.sock:/var/run/docker.sock ghcr.io/coollabsio/coolify-builder",
            ], isDebuggable: true);

            // Import git repository
            $this->executeNow([
                "echo 'Done.'",
                "echo -n 'Importing {$this->application->git_repository}:{$this->application->git_branch} to {$this->workdir}... '"
            ]);

            $this->executeNow([
                ...$this->gitImport(),
            ], 'importing_git_repository');

            $this->executeNow([
                "echo 'Done.'"
            ]);

            // Get git commit
            $this->executeNow([$this->execute_in_builder("cd {$this->workdir} && git rev-parse HEAD")], 'commit_sha', hideFromOutput: true);
            $this->git_commit = $this->activity->properties->get('commit_sha');

            if (!$this->force_rebuild) {
                $this->executeNow([
                    "docker images -q {$this->application->uuid}:{$this->git_commit} 2>/dev/null",
                ], 'local_image_found', hideFromOutput: true, ignoreErrors: true);
                $image_found = Str::of($this->activity->properties->get('local_image_found'))->trim()->isNotEmpty();
                if ($image_found) {
                    $this->executeNow([
                        "echo 'Docker Image found locally with the same Git Commit SHA. Build skipped...'"
                    ]);
                    // Generate docker-compose.yml
                    $this->generateComposeFile();

                    // Stop running container
                    $this->stopRunningContainer();

                    // Start application
                    $this->startByComposeFile();
                    return;
                }
            }
            $this->executeNow([
                $this->execute_in_builder("rm -fr {$this->workdir}/.git")
            ], hideFromOutput: true);

            $this->executeNow([
                "echo -n 'Generating nixpacks configuration... '",
            ]);
            $this->executeNow([
                $this->nixpacks_build_cmd(),
                $this->execute_in_builder("cp {$this->workdir}/.nixpacks/Dockerfile {$this->workdir}/Dockerfile"),
                $this->execute_in_builder("rm -f {$this->workdir}/.nixpacks/Dockerfile"),
            ], isDebuggable: true);

            // Generate docker-compose.yml
            $this->generateComposeFile();
            $this->executeNow([
                "echo 'Done.'",
                "echo -n 'Building image... '",
            ]);

            $this->generate_build_env_variables();
            $this->add_build_env_variables_to_dockerfile();

            if ($this->application->settings->is_static) {
                $this->executeNow([
                    $this->execute_in_builder("docker build -f {$this->workdir}/Dockerfile {$this->build_args} --progress plain -t {$this->application->uuid}:{$this->git_commit}-build {$this->workdir}"),
                ], isDebuggable: true);

                $dockerfile = "FROM {$this->application->static_image}
WORKDIR /usr/share/nginx/html/
LABEL coolify.deploymentId={$this->deployment_uuid}
COPY --from={$this->application->uuid}:{$this->git_commit}-build /app/{$this->application->publish_directory} .";
                $docker_file = base64_encode($dockerfile);

                $this->executeNow([
                    $this->execute_in_builder("echo '{$docker_file}' | base64 -d > {$this->workdir}/Dockerfile-prod"),
                    $this->execute_in_builder("docker build -f {$this->workdir}/Dockerfile-prod {$this->build_args} --progress plain -t {$this->application->uuid}:{$this->git_commit} {$this->workdir}"),
                ], hideFromOutput: true);
            } else {
                $this->executeNow([
                    $this->execute_in_builder("docker build -f {$this->workdir}/Dockerfile {$this->build_args} --progress plain -t {$this->application->uuid}:{$this->git_commit} {$this->workdir}"),
                ], isDebuggable: true);
            }

            $this->executeNow([
                "echo 'Done.'",
            ]);
            // Stop running container
            $this->stopRunningContainer();

            // Start application
            $this->startByComposeFile();
        } catch (\Exception $e) {
            $this->executeNow([
                "echo '\nOops something is not okay, are you okay? 😢'",
                "echo '\n\n{$e->getMessage()}'",
            ]);
            $this->fail($e->getMessage());
        } finally {
            // Saving docker-compose.yml
            if ($this->docker_compose) {
                Storage::disk('deployments')->put(Str::kebab($this->application->name) . '/docker-compose.yml', $this->docker_compose);
            }
            $this->executeNow(["docker rm -f {$this->deployment_uuid} >/dev/null 2>&1"], hideFromOutput: true);
            dispatch(new ContainerStatusJob($this->application_uuid));
        }
    }

    private function execute_in_builder(string $command)
    {
        return "docker exec {$this->deployment_uuid} bash -c '{$command}'";
    }
    private function generate_environment_variables($ports)
    {
        $environment_variables = collect();

        foreach ($this->application->runtime_environment_variables as $env) {
            $environment_variables->push("$env->key=$env->value");
        }
        // Add PORT if not exists, use the first port as default
        if ($environment_variables->filter(fn ($env) => Str::of($env)->contains('PORT'))->isEmpty()) {
            $environment_variables->push("PORT={$ports[0]}");
        }
        return $environment_variables->all();
    }
    private function generate_env_variables()
    {
        $this->env_args = collect([]);
        foreach ($this->application->nixpacks_environment_variables as $env) {
            $this->env_args->push("--env {$env->key}={$env->value}");
        }
        $this->env_args = $this->env_args->implode(' ');
    }
    private function generate_build_env_variables()
    {
        $this->build_args = collect(["--build-arg SOURCE_COMMIT={$this->git_commit}"]);
        foreach ($this->application->build_environment_variables as $env) {
            $this->build_args->push("--build-arg {$env->key}={$env->value}");
        }
        $this->build_args = $this->build_args->implode(' ');
    }
    private function add_build_env_variables_to_dockerfile()
    {
        $this->executeNow([
            $this->execute_in_builder("cat {$this->workdir}/Dockerfile")
        ], propertyName: 'dockerfile', hideFromOutput: true);
        $dockerfile = collect(Str::of($this->activity->properties->get('dockerfile'))->trim()->explode("\n"));

        foreach ($this->application->build_environment_variables as $env) {
            $dockerfile->splice(1, 0, "ARG {$env->key}={$env->value}");
        }
        $dockerfile_base64 = base64_encode($dockerfile->implode("\n"));
        $this->executeNow([
            $this->execute_in_builder("echo '{$dockerfile_base64}' | base64 -d > {$this->workdir}/Dockerfile")
        ], hideFromOutput: true);
    }
    private function generate_docker_compose()
    {
        $ports = $this->application->settings->is_static ? [80] : $this->application->ports_exposes_array;
        $persistentStorages = $this->generate_local_persistent_volumes();
        $volume_names = $this->generate_local_persistent_volumes_only_volume_names();
        $environment_variables = $this->generate_environment_variables($ports);
        $docker_compose = [
            'version' => '3.8',
            'services' => [
                $this->application->uuid => [
                    'image' => "{$this->application->uuid}:$this->git_commit",
                    'container_name' => $this->application->uuid,
                    'restart' => 'always',
                    'environment' => $environment_variables,
                    'labels' => $this->set_labels_for_applications(),
                    'expose' => $ports,
                    'networks' => [
                        $this->destination->network,
                    ],
                    'healthcheck' => [
                        'test' => [
                            'CMD-SHELL',
                            $this->generate_healthcheck_commands()
                        ],
                        'interval' => $this->application->health_check_interval . 's',
                        'timeout' => $this->application->health_check_timeout . 's',
                        'retries' => $this->application->health_check_retries,
                        'start_period' => $this->application->health_check_start_period . 's'
                    ],
                    'mem_limit' => $this->application->limits_memory,
                    'memswap_limit' => $this->application->limits_memory_swap,
                    'mem_swappiness' => $this->application->limits_memory_swappiness,
                    'mem_reservation' => $this->application->limits_memory_reservation,
                    'oom_kill_disable' => $this->application->limits_memory_oom_kill,
                    'cpus' => $this->application->limits_cpus,
                    'cpuset' => $this->application->limits_cpuset,
                    'cpu_shares' => $this->application->limits_cpu_shares,
                ]
            ],
            'networks' => [
                $this->destination->network => [
                    'external' => false,
                    'name' => $this->destination->network,
                    'attachable' => true,
                ]
            ]
        ];
        if (count($this->application->ports_mappings_array) > 0) {
            $docker_compose['services'][$this->application->uuid]['ports'] = $this->application->ports_mappings_array;
        }
        if (count($persistentStorages) > 0) {
            $docker_compose['services'][$this->application->uuid]['volumes'] = $persistentStorages;
        }
        if (count($volume_names) > 0) {
            $docker_compose['volumes'] = $volume_names;
        }
        return Yaml::dump($docker_compose, 10);
    }
    private function generate_local_persistent_volumes()
    {
        foreach ($this->application->persistentStorages as $persistentStorage) {
            $volume_name = $persistentStorage->host_path ?? $persistentStorage->name;
            $local_persistent_volumes[] = $volume_name . ':' . $persistentStorage->mount_path;
        }
        return $local_persistent_volumes ?? [];
    }

    private function generate_local_persistent_volumes_only_volume_names()
    {
        foreach ($this->application->persistentStorages as $persistentStorage) {
            if ($persistentStorage->host_path) {
                continue;
            }
            $local_persistent_volumes_names[$persistentStorage->name] = [
                'name' => $persistentStorage->name,
                'external' => false,
            ];
        }
        return $local_persistent_volumes_names ?? [];
    }
    private function generate_healthcheck_commands()
    {
        if (!$this->application->health_check_port) {
            $this->application->health_check_port = $this->application->ports_exposes_array[0];
        }
        if ($this->application->health_check_path) {
            $generated_healthchecks_commands = [
                "curl -s -X {$this->application->health_check_method} -f {$this->application->health_check_scheme}://{$this->application->health_check_host}:{$this->application->health_check_port}{$this->application->health_check_path} > /dev/null"
            ];
        } else {
            $generated_healthchecks_commands = [
                "curl -s -X {$this->application->health_check_method} -f {$this->application->health_check_scheme}://{$this->application->health_check_host}:{$this->application->health_check_port}/"
            ];
        }
        return implode(' ', $generated_healthchecks_commands);
    }

    private function set_labels_for_applications()
    {
        $labels = [];
        $labels[] = 'coolify.managed=true';
        $labels[] = 'coolify.version=' . config('version');
        $labels[] = 'coolify.applicationId=' . $this->application->id;
        $labels[] = 'coolify.type=application';
        $labels[] = 'coolify.name=' . $this->application->name;
        if ($this->application->fqdn) {
            $domains = Str::of($this->application->fqdn)->explode(',');
            $labels[] = 'traefik.enable=true';
            foreach ($domains as $domain) {
                $url = Url::fromString($domain);
                $host = $url->getHost();
                $path = $url->getPath();
                $slug = Str::slug($url);
                $label_id = "{$this->application->uuid}-{$slug}";
                if ($path === '/') {
                    $labels[] = "traefik.http.routers.{$label_id}.rule=Host(`{$host}`) && Path(`{$path}`)";
                } else {
                    $labels[] = "traefik.http.routers.{$label_id}.rule=Host(`{$host}`) && PathPrefix(`{$path}`)";
                    $labels[] =  "traefik.http.routers.{$label_id}.middlewares={$label_id}-stripprefix";
                    $labels[] =  "traefik.http.middlewares.{$label_id}-stripprefix.stripprefix.prefixes={$path}";
                }
            }
        }
        return $labels;
    }

    private function executeNow(
        array|Collection $command,
        string $propertyName = null,
        bool $isFinished = false,
        bool $hideFromOutput = false,
        bool $isDebuggable = false,
        bool $ignoreErrors = false
    ) {
        static::$batch_counter++;

        if ($command instanceof Collection) {
            $commandText = $command->implode("\n");
        } else {
            $commandText = collect($command)->implode("\n");
        }

        $this->activity->properties = $this->activity->properties->merge([
            'command' => $commandText,
        ]);
        $this->activity->save();
        if ($isDebuggable && !$this->application->settings->is_debug) {
            $hideFromOutput = true;
        }
        $remoteProcess = resolve(RunRemoteProcess::class, [
            'activity' => $this->activity,
            'hideFromOutput' => $hideFromOutput,
            'isFinished' => $isFinished,
            'ignoreErrors' => $ignoreErrors,
        ]);
        $result = $remoteProcess();
        if ($propertyName) {
            $this->activity->properties = $this->activity->properties->merge([
                $propertyName => trim($result->output()),
            ]);
            $this->activity->save();
        }

        if ($result->exitCode() != 0 && $result->errorOutput() && !$ignoreErrors) {
            throw new \RuntimeException($result->errorOutput());
        }
    }
    private function setGitImportSettings($git_clone_command)
    {
        if ($this->application->git_commit_sha !== 'HEAD') {
            $git_clone_command = "{$git_clone_command} && cd {$this->workdir} && git -c advice.detachedHead=false checkout {$this->application->git_commit_sha} >/dev/null 2>&1";
        }
        if ($this->application->settings->is_git_submodules_allowed) {
            $git_clone_command = "{$git_clone_command} && cd {$this->workdir} && git submodule update --init --recursive";
        }
        if ($this->application->settings->is_git_lfs_allowed) {
            $git_clone_command = "{$git_clone_command} && cd {$this->workdir} && git lfs pull";
        }
        return $git_clone_command;
    }
    private function gitImport()
    {
        $git_clone_command = "git clone -q -b {$this->application->git_branch}";

        if ($this->application->deploymentType() === 'source') {
            $source_html_url = data_get($this->application, 'source.html_url');
            $url = parse_url(filter_var($source_html_url, FILTER_SANITIZE_URL));
            $source_html_url_host = $url['host'];
            $source_html_url_scheme = $url['scheme'];

            if ($this->source->getMorphClass() == 'App\Models\GithubApp') {
                if ($this->source->is_public) {
                    $git_clone_command = "{$git_clone_command} {$this->source->html_url}/{$this->application->git_repository} {$this->workdir}";
                    $git_clone_command = $this->setGitImportSettings($git_clone_command);
                    return [
                        $this->execute_in_builder($git_clone_command)
                    ];
                } else {
                    $github_access_token = generate_github_installation_token($this->source);
                    return [
                        $this->execute_in_builder("git clone -q -b {$this->application->git_branch} $source_html_url_scheme://x-access-token:$github_access_token@$source_html_url_host/{$this->application->git_repository}.git {$this->workdir}")
                    ];
                }
            }
        }
        if ($this->application->deploymentType() === 'deploy_key') {
            $private_key = base64_encode($this->application->private_key->private_key);
            $git_clone_command = "GIT_SSH_COMMAND=\"ssh -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\" {$git_clone_command} {$this->application->git_full_url} {$this->workdir}";
            $git_clone_command = $this->setGitImportSettings($git_clone_command);
            return [
                $this->execute_in_builder("mkdir -p /root/.ssh"),
                $this->execute_in_builder("echo '{$private_key}' | base64 -d > /root/.ssh/id_rsa"),
                $this->execute_in_builder("chmod 600 /root/.ssh/id_rsa"),
                $this->execute_in_builder($git_clone_command)
            ];
        }
    }
    private function nixpacks_build_cmd()
    {
        $this->generate_env_variables();
        $nixpacks_command = "nixpacks build -o {$this->workdir} {$this->env_args} --no-error-without-start";
        if ($this->application->build_command) {
            $nixpacks_command .= " --build-cmd \"{$this->application->build_command}\"";
        }
        if ($this->application->start_command) {
            $nixpacks_command .= " --start-cmd \"{$this->application->start_command}\"";
        }
        if ($this->application->install_command) {
            $nixpacks_command .= " --install-cmd \"{$this->application->install_command}\"";
        }
        $nixpacks_command .= " {$this->workdir}";
        return $this->execute_in_builder($nixpacks_command);
    }
}
