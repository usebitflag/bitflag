import {
  PhChartLine,
  PhCircle,
  PhFlag,
  PhFlask,
  PhGearSix,
  PhGitDiff,
  PhHouse,
  PhPlugsConnected,
  PhScroll,
  PhUsersThree,
} from '@phosphor-icons/vue';
import type { Component } from 'vue';

const ICONS: Record<string, Component> = {
  house: PhHouse,
  flag: PhFlag,
  'users-three': PhUsersThree,
  'git-diff': PhGitDiff,
  flask: PhFlask,
  'chart-line': PhChartLine,
  scroll: PhScroll,
  'plugs-connected': PhPlugsConnected,
  'gear-six': PhGearSix,
};

/** The Phosphor icon for a navigation entry; unknown names fall back to a plain circle. */
export function iconFor(name: string): Component {
  return ICONS[name] ?? PhCircle;
}
