<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="mumb-profile-download">
	<a class="button button-primary" href="<?php echo esc_url( $download_url_safe ?? '#' ); ?>" download><?php echo esc_html( $label_safe ?? __( 'Download VPN Profile', 'mikrotik-user-manager-bridge' ) ); ?></a>
	<?php if ( ! empty( $ttl_display ) ) : ?>
		<p class="description"><?php printf( esc_html__( 'The download link expires in %d minute(s).', 'mikrotik-user-manager-bridge' ), (int) $ttl_display ); ?></p>
	<?php endif; ?>
</div>
