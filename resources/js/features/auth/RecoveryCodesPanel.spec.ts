import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import RecoveryCodesPanel from './RecoveryCodesPanel.vue';

const codes = ['abcde-fghij', 'klmno-pqrst'];

describe('recovery codes panel', () => {
  it('lists the codes and says they are shown once', () => {
    const page = mount(RecoveryCodesPanel, { props: { codes } });

    expect(page.findAll('[data-test="recovery-code"]').map((li) => li.text())).toEqual(codes);
    expect(page.text()).toContain('only time we show them');
  });

  it('copies the codes, one per line', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.assign(navigator, { clipboard: { writeText } });
    const page = mount(RecoveryCodesPanel, { props: { codes } });

    await page.get('[data-test="recovery-copy"]').trigger('click');

    expect(writeText).toHaveBeenCalledWith('abcde-fghij\nklmno-pqrst');
  });

  it('reports when the person confirms they stored the codes', async () => {
    const page = mount(RecoveryCodesPanel, { props: { codes } });

    await page.get('[data-test="recovery-ack"] input').setValue(true);

    expect(page.emitted('acknowledged')?.at(-1)).toEqual([true]);
  });
});
