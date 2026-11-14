import { spawn } from 'node:child_process';
import { cp, copyFile, mkdir, readFile, readdir, rename, rm } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const stagingDirectory = join(projectRoot, 'storage/framework/vite-build-staging');
const buildDirectory = join(projectRoot, 'public/build');
const manifestName = 'manifest.json';
const stagingManifest = join(stagingDirectory, manifestName);
const publishedManifest = join(buildDirectory, manifestName);
const nextManifest = join(buildDirectory, `.manifest-${process.pid}.tmp`);
const viteCli = join(projectRoot, 'node_modules/vite/bin/vite.js');

await rm(stagingDirectory, { recursive: true, force: true });

try {
    const exitCode = await new Promise((resolveExit, reject) => {
        const vite = spawn(process.execPath, [viteCli, 'build', '--outDir', stagingDirectory], {
            cwd: projectRoot,
            stdio: 'inherit',
        });

        vite.once('error', reject);
        vite.once('exit', (code) => resolveExit(code ?? 1));
    });

    if (exitCode !== 0) {
        process.exitCode = exitCode;
        throw new Error(`Vite build failed with exit code ${exitCode}.`);
    }

    // Validate the new manifest before touching the currently served build.
    JSON.parse(await readFile(stagingManifest, 'utf8'));
    await mkdir(buildDirectory, { recursive: true });

    // Publish fingerprinted assets first; keep old files for already-open pages.
    for (const entry of await readdir(stagingDirectory, { withFileTypes: true })) {
        if (entry.name === manifestName) continue;

        await cp(
            join(stagingDirectory, entry.name),
            join(buildDirectory, entry.name),
            { recursive: true, force: true },
        );
    }

    // Replacing the manifest last keeps Laravel on the previous complete build
    // until all assets referenced by the new manifest are present.
    await copyFile(stagingManifest, nextManifest);
    await rename(nextManifest, publishedManifest);
} finally {
    await rm(nextManifest, { force: true });
    await rm(stagingDirectory, { recursive: true, force: true });
}
