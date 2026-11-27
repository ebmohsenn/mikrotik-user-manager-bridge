export interface PaginationMeta {
  page: number;
  per_page: number;
  total: number;
  total_pages: number;
}

export interface ApiListResponse<T> {
  success: boolean;
  data: T[];
  meta?: PaginationMeta;
  message?: string;
}

export interface ApiResponse<T = unknown> {
  success: boolean;
  data?: T;
  message?: string;
  code?: string;
}

export interface UserProfileAssignment {
  profile?: string;
  expires_at?: string | null;
  [key: string]: unknown;
}

export interface User {
  id?: string;
  username: string;
  group?: string;
  profile?: string;
  comment?: string;
  disabled?: boolean | string;
  expires_at?: string | null;
  status?: string;
  assignments?: UserProfileAssignment[];
  [key: string]: unknown;
}

export interface Profile {
  name: string;
  validity?: string;
  updated_at?: string;
  [key: string]: unknown;
}

export interface Group {
  name: string;
  attributes?: Record<string, unknown> | string;
  updated_at?: string;
  [key: string]: unknown;
}

export interface Router {
  id?: string;
  name: string;
  address: string;
  comment?: string;
  disabled?: boolean;
  coa_port?: number;
  shared_secret?: string;
  [key: string]: unknown;
}

export interface RouterCapabilities {
  reason?: string;
  binary?: {
    available?: boolean;
    dual_namespace?: boolean;
    error?: string | null;
    [key: string]: unknown;
  };
  rest?: {
    configured?: boolean;
    available?: boolean;
    base_path?: string | null;
    error?: string | null;
    [key: string]: unknown;
  };
  profile_updates?: {
    binary?: boolean;
    rest?: boolean;
  };
  quirks?: {
    user_profile_field?: string;
    [key: string]: unknown;
  };
  [key: string]: unknown;
}

export interface QueueJob {
  key: string;
  type: string;
  status: string;
  attempts: number;
  next_run?: number;
  last_error?: string;
  payload: Record<string, unknown>;
  created_at?: number;
  updated_at?: number;
}

declare global {
  interface Window {
    MWP_BOOTSTRAP?: {
      rest?: {
        mwpBase?: string;
        mikrotikBase?: string;
        nonce?: string;
      };
      i18n?: Record<string, string>;
    };
  }
}

export {};
