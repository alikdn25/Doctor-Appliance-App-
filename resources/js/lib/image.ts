/**
 * Shrinks a camera photo before upload: 12 MP originals take minutes on one bar of signal.
 * Returns a JPEG no larger than maxSide pixels; falls back to the original file if the
 * browser cannot decode it (e.g. HEIC on Android).
 */
export async function compressImage(
    file: File,
    maxSide = 1600,
    quality = 0.8,
): Promise<{ blob: Blob; filename: string }> {
    try {
        const bitmap = await createImageBitmap(file, {
            imageOrientation: 'from-image',
        });
        const scale = Math.min(
            1,
            maxSide / Math.max(bitmap.width, bitmap.height),
        );
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        canvas
            .getContext('2d')
            ?.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close();

        const blob = await new Promise<Blob | null>((resolve) =>
            canvas.toBlob(resolve, 'image/jpeg', quality),
        );

        if (blob && blob.size < file.size) {
            return {
                blob,
                filename: file.name.replace(/\.[^.]+$/, '') + '.jpg',
            };
        }
    } catch {
        // Fall through to the original file.
    }

    return { blob: file, filename: file.name || 'photo.jpg' };
}
