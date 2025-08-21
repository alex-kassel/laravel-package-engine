<?php

declare(strict_types=1);

namespace AlexKassel\LaravelPackageEngine\Commands;

use Illuminate\Console\Command;
use AlexKassel\LaravelPackageEngine\Support\LocalRegistry;

class RemakePackagesCommand extends Command
{
    protected $signature = 'packages:remake {names?* : vendor/package list or --all} {--all} {--i|install} {--d|dev} {--branch=} {--path=}';
    protected $description = 'Remove and recreate package(s) (delete dir, re-create from stubs, optional install)';

    public function handle(): int
    {
        $targets = [];
        if ($this->option('all')) {
            $reg = new LocalRegistry();
            $targets = array_keys($reg->all());
            if (empty($targets)) {
                $this->error('No self-created packages recorded.');
                return 1;
            }
        } else {
            $names = (array) $this->argument('names');
            if (empty($names)) {
                $this->error('Provide one or more vendor/package names or use --all.');
                return 1;
            }
            foreach ($names as $n) {
                if (!preg_match('#^[a-z0-9\-]+/[a-z0-9\-]+$#i', (string) $n)) {
                    $this->error("Invalid package name: {$n}");
                    return 1;
                }
                $targets[] = (string) $n;
            }
        }

        foreach ($targets as $name) {
            // Remove, then re-create (deletes dir by default)
            $this->call('packages:remove', ['names' => [$name]]);
            $args = [
                'names' => [$name],
                '--install' => (bool) $this->option('install'),
                '--dev' => (bool) $this->option('dev'),
                '--branch' => $this->option('branch'),
            ];
            if ($this->option('path')) {
                $args['--path'] = $this->option('path');
            }
            $this->call('packages:make', $args);
        }

        return 0;
    }
}
