// @vitest-environment node
import { mkdirSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { scan } from './scan-bundle-secrets.mjs';

function build(files) {
  const dir = mkdtempSync(join(tmpdir(), 'bf-bundle-'));
  for (const [name, content] of Object.entries(files)) {
    const path = join(dir, name);
    mkdirSync(join(path, '..'), { recursive: true });
    writeFileSync(path, content);
  }
  return dir;
}

describe('scan', () => {
  it('passes a build with no secrets, and leaves public SDK keys alone', () => {
    const dir = build({ 'assets/app-abc.js': 'const key = "bf_pub_abc123"; export default 1;' });
    expect(scan(dir)).toEqual([]);
  });

  it.each(['bf_srv_', 'bf_mgt_', 'bf_pat_', 'whsec_', 'APP_KEY', 'DB_PASSWORD', 'STRIPE_'])(
    'flags %s',
    (needle) => {
      const dir = build({ 'assets/deep/chunk.js': `x("${needle}secret")` });
      expect(scan(dir)).toEqual([`assets/deep/chunk.js: contains "${needle}"`]);
    },
  );

  it('flags a development page chunk by its name', () => {
    const dir = build({
      'assets/UiGallery-CGBvVQJg.js': 'export default 1',
      'assets/Login-abc.js': 'export default 2',
    });
    expect(scan(dir)).toEqual([
      'assets/UiGallery-CGBvVQJg.js: a development-only page is in the build',
    ]);
  });

  it('reports every problem, file by file', () => {
    const dir = build({ 'a.js': 'bf_srv_1 APP_KEY', 'b.js': 'STRIPE_SECRET' });
    expect(scan(dir)).toEqual([
      'a.js: contains "bf_srv_"',
      'a.js: contains "APP_KEY"',
      'b.js: contains "STRIPE_"',
    ]);
  });
});
