import React from 'react';
import apiClient from '../api/apiClient';
import type { ApiListResponse, User } from '../types';

const cache = new Map<string, { data: ApiListResponse<User>; expires: number }>();

interface UseUsersOptions {
  page: number;
  perPage: number;
  search: string;
  force?: boolean;
}

interface UseUsersState {
  loading: boolean;
  response?: ApiListResponse<User>;
  error?: Error;
}

export function useUsers(base: string, headers: Record<string, string>, options: UseUsersOptions) {
  const { page, perPage, search, force } = options;
  const key = JSON.stringify({ base, page, perPage, search });
  const [state, setState] = React.useState<UseUsersState>({ loading: true });

  React.useEffect(() => {
    let cancelled = false;
    async function run() {
      setState((prev) => ({ ...prev, loading: true }));
      const cached = cache.get(key);
      const now = Date.now();
      if (!force && cached && cached.expires > now) {
        setState({ loading: false, response: cached.data });
        return;
      }
      try {
        const params: Record<string, string | number> = {
          page,
          per_page: perPage
        };
        if (search) {
          params.search = search;
        }
        if (force) {
          params.force = 1;
        }

        const data = await apiClient.get<ApiListResponse<User>>('users', {
          params,
          headers
        });
        if (!cancelled) {
          cache.set(key, { data, expires: Date.now() + 30_000 });
          setState({ loading: false, response: data });
        }
      } catch (error) {
        if (!cancelled) setState({ loading: false, error: error as Error });
      }
    }
    run();
    return () => {
      cancelled = true;
    };
  }, [base, headers, page, perPage, search, force, key]);

  return state;
}

export function invalidateUsersCache() {
  cache.clear();
}
