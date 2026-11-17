/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** URL de base de forms_api (ex. http://localhost:8000) */
  readonly VITE_API_URL: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
