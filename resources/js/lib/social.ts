export type SocialPlatform = 'instagram' | 'tiktok' | 'snapchat';

const PROFILE_BASE: Record<SocialPlatform, string> = {
    instagram: 'https://www.instagram.com/',
    tiktok: 'https://www.tiktok.com/@',
    snapchat: 'https://www.snapchat.com/add/',
};

/** The handle alone, as shown on the card — no '@', no URL around it. */
export function socialHandle(raw: string | null | undefined): string | null {
    if (!raw) return null;
    const cleaned = raw.trim().split(/[?#]/)[0].replace(/\/+$/, '');
    const last = cleaned.split('/').pop() ?? '';
    return last.replace(/^@/, '').trim() || null;
}

/** Profile link: a pasted URL as is, otherwise built from the handle. */
export function socialLink(platform: SocialPlatform, raw: string | null | undefined): string | null {
    const handle = socialHandle(raw);
    if (!handle) return null;
    const value = raw!.trim();
    return /^https?:\/\//i.test(value) ? value : PROFILE_BASE[platform] + handle;
}
