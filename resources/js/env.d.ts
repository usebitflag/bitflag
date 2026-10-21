/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_APP_NAME?: string;
  /** Set to `true` for a build that must include the development pages, such as the end-to-end build. */
  readonly VITE_INCLUDE_DEV_PAGES?: string;
}
