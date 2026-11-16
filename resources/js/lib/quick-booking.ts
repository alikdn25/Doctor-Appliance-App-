/**
 * Quick booking hands its text and the AI's reading to Book customer in memory only (never localStorage or
 * sessionStorage): closing the window, a reload, saving the booking or a draft, or ✕ clears it, so a new booking
 * always opens empty. "Back to the text" from the filled form finds the text while the booking is still open.
 */
let text = '';
let result: unknown = null;
let jobDraft: unknown = null;

export const quickBookingText = () => text;

export const keepQuickBookingText = (value: string) => {
    text = value;
};

/** What the AI read, handed to Book customer (read once there). */
export const handQuickResult = (value: unknown) => {
    result = value;
};

export const takeQuickResult = <T>(): T | null => {
    const value = result as T | null;
    result = null;

    return value;
};

/** What was typed on Book customer, handed to the full job form ("⋯", read once there). */
export const handJobDraft = (value: unknown) => {
    jobDraft = value;
};

export const takeJobDraft = <T>(): T | null => {
    const value = jobDraft as T | null;
    jobDraft = null;

    return value;
};

/** The booking is closed (✕, back, saved, saved as a draft): nothing of it is kept. */
export const clearBooking = () => {
    text = '';
    result = null;
};
