import { safeSameOriginUrl } from '@/security/dom-url';

describe('safeSameOriginUrl', () => {
  it('resolves app-relative URLs against this origin', () => {
    expect(safeSameOriginUrl('/wedding/hls/ceremony/master.m3u8')).toBe(
      'http://localhost/wedding/hls/ceremony/master.m3u8',
    );
  });

  it.each([
    'javascript:alert(1)',
    'data:text/html,<script>alert(1)</script>',
    '//evil.example/path',
    'https://evil.example/path',
    null,
    42,
  ])('rejects %p', (value) => {
    expect(safeSameOriginUrl(value)).toBeNull();
  });

  it('encodes HTML metacharacters', () => {
    expect(safeSameOriginUrl('/x?a="b"')).toBe('http://localhost/x?a=%22b%22');
  });
});
