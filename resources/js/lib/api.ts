export class ApiError extends Error {
    constructor(message: string, public readonly status: number) {
        super(message);
    }
}

async function parseError(res: Response): Promise<ApiError> {
    try {
        const body = await res.json() as { error?: string };
        return new ApiError(body.error ?? `HTTP ${res.status}`, res.status);
    } catch {
        return new ApiError(`HTTP ${res.status}`, res.status);
    }
}

export async function apiPost<T>(url: string, body: Record<string, unknown>): Promise<T> {
    const res = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        },
        signal: AbortSignal.timeout(300_000), // 5 min — free tier retries take time
        body: JSON.stringify(body),
    });

    if (!res.ok) throw await parseError(res);
    return res.json() as Promise<T>;
}

export async function apiUpload<T>(url: string, formData: FormData): Promise<T> {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        signal: AbortSignal.timeout(300_000),
        body: formData,
    });

    if (!res.ok) throw await parseError(res);
    return res.json() as Promise<T>;
}

export async function apiDelete(url: string): Promise<void> {
    await fetch(url, {
        method: 'DELETE',
        headers: { 'Accept': 'application/json' },
    });
}
