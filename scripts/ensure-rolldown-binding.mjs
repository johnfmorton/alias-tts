// Vite 8 bundles with rolldown, whose native binding is a platform-specific
// optional dependency. node_modules is shared between the Mac host and the
// DDEV (linux arm64) container, but npm only installs the binding for the
// platform that ran `npm install` — so the other side's build dies with
// MODULE_NOT_FOUND deep inside rolldown. Chained ahead of `vite build` / `vite`
// in package.json (NOT a pre-hook — .npmrc's ignore-scripts=true disables
// those): install this platform's binding if it's missing, --no-save so
// package.json and the lockfile stay platform-neutral.
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

// Only the triples this project builds on; both environments are glibc/arm64
// today, but process.arch keeps an Intel Mac or amd64 Docker host working.
const triple = {
    darwin: `darwin-${process.arch}`,
    linux: `linux-${process.arch}-gnu`,
}[process.platform];
if (!triple) {
    console.warn(`[rolldown-binding] unhandled platform ${process.platform} — letting the build fail on its own if the binding is missing`);
    process.exit(0);
}

const pkg = `@rolldown/binding-${triple}`;
const { version } = require('rolldown/package.json');
// The binding must exist AND match rolldown's version — after a vite upgrade
// bumps rolldown, a leftover --no-save binding would otherwise pass an
// existence check and feed rolldown a mismatched .node file.
let installed = null;
try {
    installed = require(`${pkg}/package.json`).version;
} catch {
    // not installed for this platform
}
if (installed !== version) {
    console.log(`[rolldown-binding] installing ${pkg}@${version} for this platform (had: ${installed ?? 'none'})…`);
    execFileSync('npm', ['install', '--no-save', '--prefer-offline', `${pkg}@${version}`], { stdio: 'inherit' });
}
