import { describe, expect, it } from 'vitest';
import { nextTick, ref } from 'vue';
import { suggestKey, useKeySlug } from './useKeySlug';

describe('suggestKey', () => {
  it.each([
    ['QA', 'qa'],
    ['Pre production', 'pre-production'],
    ['  Staging EU!! ', 'staging-eu'],
    ['1st stage', 'st-stage'],
    ['', ''],
  ])('turns %j into %j', (name, key) => {
    expect(suggestKey(name)).toBe(key);
  });
});

describe('useKeySlug', () => {
  it('follows the name until the key is edited by hand', async () => {
    const name = ref('');
    const key = ref('');
    const slug = useKeySlug(name, key, ref([]));

    name.value = 'Pre production';
    await nextTick();
    expect(key.value).toBe('pre-production');

    slug.setKey('preprod');
    name.value = 'Something else';
    await nextTick();
    expect(key.value).toBe('preprod');

    slug.reset();
    name.value = 'Another';
    await nextTick();
    expect(key.value).toBe('another');
  });

  it('names the problem with a key', () => {
    const key = ref('');
    const slug = useKeySlug(ref(''), key, ref(['staging']));

    expect(slug.problem()).toBeNull();
    key.value = '9lives';
    expect(slug.problem()).toBe('Start with a letter. Use a–z, 0–9 and -');
    key.value = 'staging';
    expect(slug.problem()).toBe('Already used in this project');
    key.value = 'qa';
    expect(slug.problem()).toBeNull();
  });
});
