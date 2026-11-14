/** A file size as the attachment card shows it ("1.5 MB"); the same wording as the app's file helpers. */
export const convertBytesToSizeString = (bytes: number): string => {
  const sizes = ["Bytes", "KB", "MB", "GB", "TB"];
  if (bytes === 0) {
    return "0 Byte";
  }
  const sizeIndex = Math.floor(Math.log(bytes) / Math.log(1024));
  return parseFloat((bytes / Math.pow(1024, sizeIndex)).toFixed(2)) + " " + sizes[sizeIndex];
};
