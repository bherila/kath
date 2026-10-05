import { act, render, screen, waitFor } from '@testing-library/react';

import { type GalleryPage, requestJson } from '@/wedding/api';
import { Gallery } from '@/wedding/Gallery';

jest.mock('@/wedding/api', () => ({
  ...jest.requireActual('@/wedding/api'),
  requestJson: jest.fn(),
}));

const request = jest.mocked(requestJson);

function page(...names: string[]): GalleryPage {
  return {
    next_cursor: null,
    items: names.map((name) => ({
      ulid: name,
      kind: 'photo',
      guest_name: name,
      mine: false,
      created_at: '2026-10-04T00:00:00Z',
      thumb_url: null,
      display_url: null,
      original_url: `/wedding/media/${name}/original`,
      master_url: null,
      processing: false,
    })),
  };
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
});
