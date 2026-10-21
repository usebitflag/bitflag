// Fails when the built frontend contains something that must never reach a browser.
//   node scripts/ci/scan-bundle-secrets.mjs [directory]     directory defaults to public/build
//
// Two things are checked in every file under the directory:
//   - text that marks a secret: server, management and personal-token key prefixes, webhook signing secrets, and the
//     names of server-only environment variables. Public SDK keys (bf_pub_) are meant for browsers and are not flagged.
//   - development-only pages, which a production build leaves out.
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

export const FORBIDDEN_TEXT = [
  'bf_srv_',
  'bf_mgt_',
  'bf_pat_',
  'whsec_',
  'APP_KEY',
  'DB_PASSWORD',
  'STRIPE_',
];

/** File names of pages that exist for development only. */
export const FORBIDDEN_FILES = [/^UiGallery[-.]/];

function* walk(directory) {
  for (const name of readdirSync(directory)) {
    const path = join(directory, name);
    if (statSync(path).isDirectory()) yield* walk(path);
    else yield path;
  }
}

/** Every problem found in the built files under `directory`, as readable lines. */
export function scan(directory) {
  const problems = [];

  for (const path of walk(directory)) {
    const name = path.split('/').at(-1) ?? path;
    const shown = relative(directory, path);

    if (FORBIDDEN_FILES.some((pattern) => pattern.test(name))) {
      problems.push(`${shown}: a development-only page is in the build`);
    }

    const text = readFileSync(path, 'utf8');
    for (const needle of FORBIDDEN_TEXT) {
      if (text.includes(needle)) problems.push(`${shown}: contains "${needle}"`);
    }
  }

  return problems;
}

function main() {
  const directory = process.argv[2] ?? 'public/build';

  if (!existsSync(directory)) {
    // A scan that finds no build must not pass: it would prove nothing.
    console.error(`No build found at ${directory}. Run the build first.`);
    process.exit(1);
  }

  const problems = scan(directory);

  if (problems.length > 0) {
    console.error(
      `The built frontend must not contain secrets or development pages:\n${problems.join('\n')}`,
    );
    process.exit(1);
  }

  console.log(`Scanned ${directory}: no secrets and no development pages.`);
}

if (process.argv[1] === fileURLToPath(import.meta.url)) main();
