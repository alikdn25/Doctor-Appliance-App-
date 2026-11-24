/**
 * Quick booking hands its text and the AI's reading to Book customer in memory only (never localStorage or
 * sessionStorage): closing the window, a reload, saving the booking or a draft, or ✕ clears it, so a new booking
 * always opens empty. "Back to the text" from the filled form finds the text while the booking is still open.
 */
let text = '';
let photos: QuickBookingPhoto[] = [];
let result: unknown = null;
let jobDraft: unknown = null;
let parseOnOpen = false;

/** A photo picked on Quick booking (shrunk on the phone to 1600 px), with its preview. */
export type QuickBookingPhoto = {
    id: string;
    blob: Blob;
    filename: string;
    preview: string;
};

export const MAX_BOOKING_PHOTOS = 5;

export const quickBookingText = () => text;

/** The photos picked on Quick booking, kept like the text for "Back to the text". */
export const quickBookingPhotos = () => photos;

export const keepQuickBookingPhotos = (value: QuickBookingPhoto[]) => {
    photos = value;
};

/**
 * "Booking from photo" in "+": the photos were picked in the menu itself, so Quick booking opens with them and starts
 * reading at once. Peeked while the old screen closes (so it does not clear them), taken once by the new one.
 */
export const startQuickBookingFromPhotos = (value: QuickBookingPhoto[]) => {
    clearBooking();
    photos = value;
    parseOnOpen = true;
};

export const quickParsePending = () => parseOnOpen;

export const takeQuickParse = () => {
    const value = parseOnOpen;
    parseOnOpen = false;

    return value;
};

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
    photos.forEach((photo) => URL.revokeObjectURL(photo.preview));
    photos = [];
    result = null;
};
