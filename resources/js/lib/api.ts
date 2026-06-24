export async function apiPost<T>(url: string, body: Record<string, unknown>): Promise<T> {
    const res = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        },
        body: JSON.stringify(body),
    });

    const data = await res.json() as T & { error?: string };
    if (!res.ok) throw new Error((data as { error?: string }).error ?? `HTTP ${res.status}`);
    return data;
}

export async function apiUpload<T>(url: string, formData: FormData): Promise<T> {
    const res = await fetch(url, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
        },
        body: formData,
    });

    const data = await res.json() as T & { error?: string };
    if (!res.ok) throw new Error((data as { error?: string }).error ?? `HTTP ${res.status}`);
    return data;
}

export async function apiDelete(url: string): Promise<void> {
    await fetch(url, {
        method: 'DELETE',
        headers: {
            'Accept': 'application/json',
        },
    });
}
