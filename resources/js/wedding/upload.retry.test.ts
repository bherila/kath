import { ApiError } from '@/wedding/api';
import { withRetries } from '@/wedding/upload';

describe('withRetries', () => {
  beforeEach(() => {
    jest.useFakeTimers();
  });

  afterEach(() => {
    jest.useRealTimers();
    Object.defineProperty(navigator, 'onLine', { value: true, configurable: true });
  });

  it('backs off between attempts and waits out time offline', async () => {
    const operation = jest.fn<Promise<string>, []>()
      .mockRejectedValueOnce(new Error('network error'))
      .mockRejectedValueOnce(new Error('network error'))
      .mockResolvedValue('ok');
    Object.defineProperty(navigator, 'onLine', { value: false, configurable: true });

    const result = withRetries(operation);
    await jest.advanceTimersByTimeAsync(1_000); // first backoff
    // Still offline: no second attempt however long it takes.
    await jest.advanceTimersByTimeAsync(5 * 60_000);
    expect(operation).toHaveBeenCalledTimes(1);

    Object.defineProperty(navigator, 'onLine', { value: true, configurable: true });
    window.dispatchEvent(new Event('online'));
    await jest.advanceTimersByTimeAsync(0);
    expect(operation).toHaveBeenCalledTimes(2);

    await jest.advanceTimersByTimeAsync(2_000); // second backoff doubles
    await expect(result).resolves.toBe('ok');
    expect(operation).toHaveBeenCalledTimes(3);
  });

  it('gives up after six attempts', async () => {
    const operation = jest.fn<Promise<string>, []>().mockRejectedValue(new Error('still down'));

    const result = withRetries(operation);
    const settled = expect(result).rejects.toThrow('still down');
    await jest.advanceTimersByTimeAsync(60_000);
    await settled;
    expect(operation).toHaveBeenCalledTimes(6);
  });

  it('throws a definitive answer at once but retries server errors', async () => {
    const gone = jest.fn<Promise<string>, []>().mockRejectedValue(new ApiError(404, 'Not found.'));
    await expect(withRetries(gone)).rejects.toThrow('Not found.');
    expect(gone).toHaveBeenCalledTimes(1);

    const flaky = jest.fn<Promise<string>, []>().mockRejectedValueOnce(new ApiError(503, 'Unavailable.')).mockResolvedValue('ok');
    const result = withRetries(flaky);
    await jest.advanceTimersByTimeAsync(1_000);
    await expect(result).resolves.toBe('ok');
    expect(flaky).toHaveBeenCalledTimes(2);
  });
});
