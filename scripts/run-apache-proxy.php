<?php

declare(strict_types=1);

$projectDirectory = str_replace('\\', '/', dirname(__DIR__));
$apache = 'C:/xampp/apache/bin/httpd.exe';
$config = 'deploy/apache/likhae-reverse-proxy.conf';
$projectRootDirective = 'Define LIKHAE_PROJECT_ROOT "'.$projectDirectory.'"';

$process = proc_open(
    [$apache, '-d', $projectDirectory, '-f', $config, '-C', $projectRootDirective],
    [STDIN, STDOUT, STDERR],
    $pipes,
    $projectDirectory,
    null,
    ['bypass_shell' => true, 'create_process_group' => true],
);

if (! is_resource($process)) {
    fwrite(STDERR, "Unable to start the Apache reverse proxy.\n");
    exit(1);
}

exit(proc_close($process));
