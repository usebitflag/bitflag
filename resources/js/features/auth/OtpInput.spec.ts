import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import OtpInput from './OtpInput.vue';

function digitBoxes(page: ReturnType<typeof mount>) {
  return page.findAll('input');
}

describe('one-time code input', () => {
  it('shows six boxes with a numeric keyboard and accessible names', () => {
    const page = mount(OtpInput, { props: { modelValue: '' }, attachTo: document.body });
    const boxes = digitBoxes(page);

    expect(boxes).toHaveLength(6);
    expect(boxes[0]?.attributes('inputmode')).toBe('numeric');
    expect(boxes[0]?.attributes('autocomplete')).toBe('one-time-code');
    expect(boxes[2]?.attributes('aria-label')).toBe('Digit 3 of 6');
    expect(page.get('[role="group"]').attributes('aria-label')).toBe('Authentication code');
  });

  it('accepts only digits and reports the code when it is complete', async () => {
    const page = mount(OtpInput, { props: { modelValue: '' }, attachTo: document.body });

    await digitBoxes(page)[0]?.setValue('a');
    expect(page.emitted('update:modelValue')).toBeUndefined();

    await digitBoxes(page)[0]?.setValue('7');
    expect(page.emitted('update:modelValue')?.at(-1)).toEqual(['7']);
  });

  it('fills every box from a pasted code, ignoring spaces and dashes', async () => {
    const page = mount(OtpInput, { props: { modelValue: '' }, attachTo: document.body });
    const data = { getData: () => '123 456' };

    await page.get('[role="group"]').trigger('paste', { clipboardData: data });

    expect(page.emitted('update:modelValue')?.at(-1)).toEqual(['123456']);
    expect(page.emitted('complete')?.at(-1)).toEqual(['123456']);
  });

  it('ignores a paste with no digits', async () => {
    const page = mount(OtpInput, { props: { modelValue: '' }, attachTo: document.body });

    await page
      .get('[role="group"]')
      .trigger('paste', { clipboardData: { getData: () => 'abcdef' } });

    expect(page.emitted('update:modelValue')).toBeUndefined();
  });

  it('marks the boxes invalid', () => {
    const page = mount(OtpInput, { props: { modelValue: '12', invalid: true } });

    expect(digitBoxes(page)[0]?.attributes('aria-invalid')).toBe('true');
    expect(digitBoxes(page)[0]?.element.value).toBe('1');
  });
});
