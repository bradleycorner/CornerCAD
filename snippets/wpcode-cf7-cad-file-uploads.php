/**
 * CF7: let CAD files through the custom-project form with their real names.
 *
 * Contact Form 7 renames any upload whose extension is not on its safelist (audio/video/image,
 * pdf, txt) to "<name>.<ext>_.txt", so a customer's part.stl arrived as part.stl_.txt
 * (measured 2026-11-22, form 6283). These are data formats, never executed by the server,
 * and the form's own filetypes: option still decides what may be uploaded at all.
 */
add_filter( 'wpcf7_file_extension_safelist', function ( $safelist ) {
	return array_merge( $safelist, array( 'stl', 'step', 'stp', '3mf', 'dxf' ) );
} );
