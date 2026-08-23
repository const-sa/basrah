/**
 * Calendar dates and clock times as the screens need them.
 *
 * A booking date is a calendar day, not an instant. `toISOString()` converts
 * to UTC first, so anywhere east of Greenwich a local midnight lands on the
 * previous day and the string comes back one day early — which is how a new
 * chalet form could open with check-out equal to check-in, showing zero
 * nights and therefore no price.
 */

/** Local calendar day as YYYY-MM-DD. */
export const toDateString = (date: Date): string =>
    `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

/** Today, in the browser's own timezone. */
export const todayString = (): string => toDateString(new Date());

/** A date shifted by whole days, staying on the local calendar throughout. */
export const addDays = (date: string, days: number): string => {
    const d = new Date(`${date}T00:00:00`);
    d.setDate(d.getDate() + days);

    return toDateString(d);
};

/** Whole days between two calendar dates — negative when they are reversed. */
export const daysBetween = (from: string, to: string): number => {
    if (!from || !to) return 0;

    const ms = new Date(`${to}T00:00:00`).getTime() - new Date(`${from}T00:00:00`).getTime();

    return Math.round(ms / 86_400_000);
};

/**
 * A 24-hour HH:MM as Arabic 12-hour, e.g. "16:00" becomes "٤:٠٠ م".
 *
 * Times are stored and edited in 24-hour form because that is what the server
 * parses; this is display only. Midnight and noon read as 12, not 0.
 */
export const formatTime12 = (time: string | null | undefined): string => {
    if (!time) return '';

    const [rawHour, rawMinute] = time.split(':');
    const hour = Number(rawHour);
    const minute = Number(rawMinute);

    if (Number.isNaN(hour) || Number.isNaN(minute)) return time;

    const suffix = hour < 12 ? 'ص' : 'م';
    const shown = hour % 12 === 0 ? 12 : hour % 12;

    return `${shown}:${String(minute).padStart(2, '0')} ${suffix}`;
};
