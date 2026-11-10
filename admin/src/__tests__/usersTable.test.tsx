import React from 'react';
import { render, screen } from '@testing-library/react';
import UsersTable from '../components/UsersTable.jsx';

describe('UsersTable', () => {
  it('renders toolbar actions', () => {
    render(<UsersTable addToast={() => undefined} />);
    expect(screen.getByText(/New User/i)).toBeInTheDocument();
    expect(screen.getByPlaceholderText(/Search users/i)).toBeInTheDocument();
  });
});
