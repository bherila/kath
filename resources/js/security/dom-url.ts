/**
 * Encode the HTML metacharacters that must never cross from DOM-hydrated text
 * into a URL-bearing property. Existing percent escapes are deliberately left
 * alone so signed object-storage URLs keep their signatures.
 */
function escapeUrlMetacharacters(value: string): string {
  return value.replace(/[<>"']/g, (character) => encodeURIComponent(character));
}

/**
 * Accept a URL only when it resolves to this application. This keeps gated
 * playback and media links from being pointed at a script or third-party URL.
 */
export function safeSameOriginUrl(value: unknown): string | null {
  if (typeof value !== 'string') {
    return null;
  }

  try {
    const parsed = new URL(value, window.location.origin);
    if (
      parsed.origin !== window.location.origin
      || (parsed.protocol !== 'http:' && parsed.protocol !== 'https:')
    ) {
      return null;
    }

    return escapeUrlMetacharacters(parsed.href);
  } catch {
    return null;
  }
}
