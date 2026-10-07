import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';

import { type GalleryItem, type GalleryPage, requestJson } from '@/wedding/api';
import { Gallery } from '@/wedding/Gallery';

jest.mock('@/wedding/api', () => ({
  ...jest.requireActual('@/wedding/api'),
  requestJson: jest.fn(),
}));

const request = jest.mocked(requestJson);

function photo(name: string, overrides: Partial<GalleryItem> = {}): GalleryItem {
  return {
    ulid: name,
    kind: 'photo',
    guest_name: name,
    mine: false,
    created_at: '2026-10-04T00:00:00Z',
    width: null,
    height: null,
    similar_count: 0,
    thumb_url: null,
    display_url: null,
    original_url: `/wedding/media/${name}/original`,
    master_url: null,
    processing: false,
    ...overrides,
  };
}

function page(...names: string[]): GalleryPage {
  return { next_cursor: null, items: names.map((name) => photo(name)) };
}

describe('Gallery', () => {
  it('ignores a slower response from a superseded refresh', async () => {
    let resolveStale: (value: GalleryPage) => void = () => {};
    request
      .mockImplementationOnce(() => new Promise((resolve) => { resolveStale = resolve as (value: GalleryPage) => void; }))
      .mockResolvedValueOnce(page('new', 'old'));

    const { rerender } = render(<Gallery refreshKey={0} />);
    // An upload finishes and the parent asks for a refresh while the first load is in flight.
    rerender(<Gallery refreshKey={1} />);
    await waitFor(() => expect(screen.getByLabelText('Open photo from new')).toBeInTheDocument());

    await act(async () => {
      resolveStale(page('old'));
    });

    expect(screen.getByLabelText('Open photo from new')).toBeInTheDocument();
    expect(screen.getAllByRole('listitem')).toHaveLength(2);
  });

  it('shows the best copy of a cluster and lets viewers pick another', async () => {
    request.mockImplementation(async (_method: string, url: string) => {
      if (url === '/wedding/api/gallery/best/similar') {
        return { items: [photo('small', { width: 1080, height: 810 })] };
      }
      return { next_cursor: null, items: [photo('best', { width: 4032, height: 3024, similar_count: 1 })] };
    });

    render(<Gallery refreshKey={0} />);
    fireEvent.click(await screen.findByLabelText(/Open photo from best/));

    expect(screen.getByRole('link', { name: /Download original \(4032×3024\)/ })).toHaveAttribute('href', 'http://localhost/wedding/media/best/original');
    fireEvent.click(screen.getByRole('button', { name: /1 similar copy/ }));
    fireEvent.click(await screen.findByRole('button', { name: 'View copy (1080×810)' }));

    expect(screen.getByRole('link', { name: /Download original \(1080×810\)/ })).toHaveAttribute('href', 'http://localhost/wedding/media/small/original');
    expect(screen.getByRole('button', { name: 'View best copy (4032×3024)' })).toHaveAttribute('aria-pressed', 'false');
  });

  it('reloads after a removal, showing a copy promoted in its place', async () => {
    jest.spyOn(window, 'confirm').mockReturnValue(true);
    let removed = false;
    request.mockImplementation(async (method: string) => {
      if (method === 'DELETE') {
        removed = true;
        return {};
      }
      // The tile looked like a single photo, but a copy arrived meanwhile.
      return { next_cursor: null, items: [removed ? photo('copy') : photo('mine', { mine: true })] };
    });

    render(<Gallery refreshKey={0} />);
    fireEvent.click(await screen.findByLabelText(/Open photo from mine/));
    fireEvent.click(screen.getByRole('button', { name: /Remove/ }));

    expect(await screen.findByLabelText(/Open photo from copy/)).toBeInTheDocument();
  });
});
