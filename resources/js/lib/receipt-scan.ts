/**
 * The receipt photo taken with the camera button in Expenses / My expenses, handed to the new-expense form in memory
 * (read once there), where the AI reads it.
 */
let photo: File | null = null;

export const handReceipt = (file: File) => {
    photo = file;
};

export const takeReceipt = (): File | null => {
    const value = photo;
    photo = null;

    return value;
};
