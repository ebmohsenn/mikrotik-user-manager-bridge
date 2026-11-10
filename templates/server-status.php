<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="mumb-server-status" id="<?php echo esc_attr( $container_safe ?? '' ); ?>" data-endpoint="<?php echo esc_url( $endpoint_safe ?? '' ); ?>">
	<p class="mumb-server-loading"><?php esc_html_e( 'Loading server status…', 'mikrotik-user-manager-bridge' ); ?></p>
</div>
