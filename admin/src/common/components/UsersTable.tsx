import React from 'react';
import type { User, PaginationMeta } from '@mwp/types';
import { translate } from '@mwp/utils/i18n';

type SortOrder = 'asc' | 'desc';

type Align = 'left' | 'right' | 'center';

export interface ColumnDefinition<T = User> {
  id: string;
  label: string;
  sortable?: boolean;
  align?: Align;
  render: (item: T, index: number) => React.ReactNode;
  headerClassName?: string;
  cellClassName?: string;
}

export interface PaginationConfig {
  meta: PaginationMeta;
  onPageChange: (page: number) => void;
  onPerPageChange?: (perPage: number) => void;
  perPageOptions?: number[];
}

export interface SortConfig {
  column?: string;
  order?: SortOrder;
  onSort: (column: string) => void;
}

export interface SharedUsersTableProps {
  users: User[];
  loading?: boolean;
  columns: ColumnDefinition<User>[];
  toolbar?: React.ReactNode;
  filters?: React.ReactNode;
  emptyState?: React.ReactNode;
  pagination?: PaginationConfig;
  sort?: SortConfig;
  rowActions?: (user: User, index: number) => React.ReactNode;
  getRowKey?: (user: User, index: number) => string;
  containerClassName?: string;
  tableClassName?: string;
  footer?: React.ReactNode;
}

function renderSortIndicator(sort: SortConfig | undefined, columnId: string) {
  if (!sort || sort.column !== columnId) {
    return null;
  }
  return <span className="mwp-sort-indicator" aria-hidden="true">{sort.order === 'asc' ? '↑' : '↓'}</span>;
}

function PaginationFooter({ pagination }: { pagination: PaginationConfig }) {
  const { meta, onPageChange, onPerPageChange, perPageOptions } = pagination;
  const totalPages = Math.max(1, meta.total_pages ?? 1);
  const currentPage = Math.min(totalPages, Math.max(1, meta.page ?? 1));

  const pages = React.useMemo(() => {
    if (totalPages <= 7) {
      return Array.from({ length: totalPages }, (_, index) => index + 1);
    }
    const set = new Set<number>();
    set.add(1);
    set.add(totalPages);
    set.add(currentPage);
    set.add(currentPage - 1);
    set.add(currentPage + 1);
    set.add(currentPage - 2);
    set.add(currentPage + 2);
    return Array.from(set)
      .filter((page) => page >= 1 && page <= totalPages)
      .sort((a, b) => a - b);
  }, [currentPage, totalPages]);

  return (
    <div className="mwp-pagination">
      <div className="mwp-pagination__pages" role="group" aria-label={translate('Pagination', 'Pagination')}>
        {pages.map((page) => (
          <button
            key={page}
            type="button"
            className={`mwp-pagination__page${page === currentPage ? ' is-active' : ''}`}
            onClick={() => onPageChange(page)}
          >
            {page}
          </button>
        ))}
      </div>
      {onPerPageChange && (
        <select
          className="mwp-pagination__per-page"
          value={meta.per_page}
          onChange={(event) => onPerPageChange(Number(event.target.value))}
        >
          {(perPageOptions || [10, 25, 50, 100]).map((option) => (
            <option key={option} value={option}>
              {translate('%d per page', `${option} per page`).replace('%d', String(option))}
            </option>
          ))}
        </select>
      )}
      <div className="mwp-pagination__summary">
        {translate('%1$d of %2$d', `${meta.total} of ${meta.total_pages}`)
          .replace('%1$d', String(meta.total || 0))
          .replace('%2$d', String(totalPages))}
      </div>
    </div>
  );
}

const UsersTable: React.FC<SharedUsersTableProps> = ({
  users,
  loading,
  columns,
  toolbar,
  filters,
  emptyState,
  pagination,
  sort,
  rowActions,
  getRowKey,
  containerClassName,
  tableClassName,
  footer
}) => {
  const renderEmpty = !loading && users.length === 0;
  return (
    <section className={`mwp-users-table ${containerClassName || ''}`}>
      {toolbar && <div className="mwp-users-table__toolbar">{toolbar}</div>}
      {filters && <div className="mwp-users-table__filters">{filters}</div>}
      <div className="mwp-users-table__body" role="region" aria-live="polite">
        <table className={tableClassName || 'mwp-users-table__table'}>
          <thead>
            <tr>
              {columns.map((column) => (
                <th
                  key={column.id}
                  className={column.headerClassName}
                  scope="col"
                  style={{ textAlign: column.align || 'left' }}
                >
                  {column.sortable && sort ? (
                    <button type="button" onClick={() => sort.onSort(column.id)}>
                      {column.label} {renderSortIndicator(sort, column.id)}
                    </button>
                  ) : (
                    column.label
                  )}
                </th>
              ))}
              {rowActions && <th scope="col" className="mwp-users-table__actions" />}
            </tr>
          </thead>
          <tbody>
            {renderEmpty && (
              <tr>
                <td colSpan={columns.length + (rowActions ? 1 : 0)} className="mwp-users-table__empty">
                  {emptyState || translate('No users found.', 'No users found.')}
                </td>
              </tr>
            )}
            {!renderEmpty && users.map((user, index) => (
              <tr key={getRowKey ? getRowKey(user, index) : user.username || index}>
                {columns.map((column) => (
                  <td
                    key={column.id}
                    className={column.cellClassName}
                    style={{ textAlign: column.align || 'left' }}
                  >
                    {column.render(user, index)}
                  </td>
                ))}
                {rowActions && <td className="mwp-users-table__actions-cell">{rowActions(user, index)}</td>}
              </tr>
            ))}
          </tbody>
        </table>
        {loading && (
          <div className="mwp-users-table__loading" role="status">
            {translate('Loading…', 'Loading…')}
          </div>
        )}
      </div>
      {pagination && <PaginationFooter pagination={pagination} />}
      {footer}
    </section>
  );
};

export default UsersTable;
