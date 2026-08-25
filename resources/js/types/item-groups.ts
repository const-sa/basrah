/**
 * Saved item groups — a stored selection of items poured into an invoice,
 * quotation or purchase in one pick.
 *
 * These live here rather than in the picker component because `<script setup>`
 * cannot export, and all four consumers (the picker and the three forms) have
 * to agree on the shape the server sends.
 */

/** One member of a group — the same shape `/admin/api/search?type=items` returns. */
export interface GroupItemOption {
    id: number;
    code: string | null;
    name: string;
    category: string | null;
    price: number;
    tax_rate: number;
    /** Present only where the screen asked for it — purchases price by cost. */
    cost?: number;
}

export interface ItemGroupOption {
    id: number;
    name: string;
    description: string | null;
    items: GroupItemOption[];
    /** Members this screen cannot use — another department's stock, say. */
    skipped_count: number;
}

/** What an insertion did, so the screen can say it out loud. */
export interface GroupInsertion {
    name: string;
    added: number;
    merged: number;
}
