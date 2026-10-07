import { sha256 } from '@noble/hashes/sha2.js';
import { bytesToHex } from '@noble/hashes/utils.js';

import { computeFileHash } from '@/wedding/imageProcessing';

function bytes(length: number): Uint8Array<ArrayBuffer> {
  const data = new Uint8Array(new ArrayBuffer(length));
  for (let i = 0; i < length; i += 1) {
    data[i] = (i * 31 + 7) & 0xff;
  }
  return data;
}

// jsdom's Blob lacks arrayBuffer() (every browser this targets has it).
if (typeof Blob.prototype.arrayBuffer !== 'function') {
  Blob.prototype.arrayBuffer = function arrayBuffer(this: Blob): Promise<ArrayBuffer> {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(reader.result as ArrayBuffer);
      reader.onerror = () => reject(reader.error);
      reader.readAsArrayBuffer(this);
    });
  };
}

describe('computeFileHash', () => {
  const realCrypto = globalThis.crypto;

  afterEach(() => {
    Object.defineProperty(globalThis, 'crypto', { value: realCrypto, configurable: true });
  });

  it('streams large files in chunks to the same SHA-256, reporting progress', async () => {
    // No Web Crypto: forces the chunked path, as for any file over its cap.
    Object.defineProperty(globalThis, 'crypto', { value: undefined, configurable: true });
    const data = bytes(20 * 1024 * 1024); // 8 MB chunks: 3 reads
    const progress: number[] = [];

    const hash = await computeFileHash(new File([data], 'clip.mov'), (fraction) => progress.push(fraction));

    // Chunked updates must equal a one-shot digest of the whole file.
    expect(hash).toBe(bytesToHex(sha256(data)));
    expect(progress).toEqual([0.4, 0.8, 1]);
  });
});
