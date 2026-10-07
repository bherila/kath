// exifr's lite build (JPEG/HEIC/TIFF readers only) has the full build's API.
declare module 'exifr/dist/lite.esm.mjs' {
  import exifr from 'exifr';

  export default exifr;
}
