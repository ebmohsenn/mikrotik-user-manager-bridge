import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import UsersTable from '../common/components/UsersTable';
import type { User, PaginationMeta } from '../types';

describe('UsersTable shared component', () => {
  const users: User[] = [
    { username: 'alice', group: 'basic' },
    { username: 'bob', group: 'premium' }
  ];
  const pagination: PaginationMeta = { page: 1, per_page: 10, total: 2, total_pages: 1 };

  it('renders rows and allows sorting callbacks', () => {
    const handleSort = jest.fn();
    render(
      <UsersTable
        users={users}
        columns={[
          {
            id: 'username',
            label: 'Username',
            sortable: true,
            render: (user) => user.username
          },
          {
            id: 'group',
            label: 'Group',
            render: (user) => user.group || ''
          }
        ]}
        sort={{ column: 'username', order: 'asc', onSort: handleSort }}
        pagination={{ meta: pagination, onPageChange: jest.fn() }}
      />
    );

    expect(screen.getByText('alice')).toBeInTheDocument();
    expect(screen.getByText('bob')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /username/i }));
    expect(handleSort).toHaveBeenCalledWith('username');
  });

  it('renders empty state when no rows', () => {
    render(
      <UsersTable
        users={[]}
        columns={[
          {
            id: 'username',
            label: 'Username',
            render: (user) => user.username
          }
        ]}
      />
    );

    expect(screen.getByText(/No users found/i)).toBeInTheDocument();
  });
});
