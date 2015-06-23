<?php 
namespace Podlove\Modules\PodloveWebPlayer;

use Podlove\Model\Episode;

class Podlove_Web_Player extends \Podlove\Modules\Base {

	protected $module_name = 'Podlove Web Player';
	protected $module_description = 'An audio player for the web. Let users listen to your podcast right on your website';
	protected $module_group = 'web publishing';

	public function load() {
		if (defined('PODLOVE_USE_PLAYER3_BETA') && PODLOVE_USE_PLAYER3_BETA) {
			$this->load_beta();
		} else {
			$this->load_legacy();
		}
	}

	public function load_beta() {

		add_action('wp', [$this, 'embed_player']);

		add_action('wp_enqueue_scripts', function() {
			wp_enqueue_script(
				'podlove-player-moderator-script',
				plugins_url('playerv3/js/podlove-web-moderator.min.js', __FILE__),
				[], \Podlove\get_plugin_header('Version')
			);
		});

		// backward compatible, but only load if no other plugin has registered this shortcode
		if (!shortcode_exists('podlove-web-player'))
			add_shortcode('podlove-web-player', [__CLASS__, 'webplayer_shortcode_beta']);

		add_shortcode('podlove-episode-web-player', [__CLASS__, 'webplayer_shortcode_beta']);
	}

	public function embed_player() {
		
		if (!filter_input(INPUT_GET, 'podloveEmbed'))
			return;

		if (!is_single())
			return;

		if (!$episode = Episode::find_or_create_by_post_id(get_the_ID()))
			return;

		$css_path = plugins_url('playerv3/css', __FILE__);
		$js_path  = plugins_url('playerv3/js', __FILE__);

		$player_config = (new Playerv3\PlayerConfig($episode))->get();

		\Podlove\load_template(
			'lib/modules/podlove_web_player/playerv3/views/embed_player', 
			compact('episode', 'css_path', 'js_path', 'player_config')
		);

		exit;
	}

	public static function webplayer_shortcode_beta() {

		if (is_feed())
			return '';

		$episode = Episode::find_or_create_by_post_id(get_the_ID());
		return (new Playerv3\HTML5Printer($episode))->render(null, [
			'data-podlove-web-player-source' => add_query_arg(['podloveEmbed' => true], get_permalink())
		]) . '<script>jQuery("audio").podlovewebplayer();</script>';
	}

	public function load_legacy() {
		add_action( 'podlove_dashboard_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_filter( 'the_content', array( $this, 'autoinsert_into_content' ) );
		add_action('wp', array( $this, 'standalone_player_page' ) );

		// backward compatible, but only load if no other plugin has registered this shortcode
		if (!shortcode_exists('podlove-web-player'))
			add_shortcode('podlove-web-player', [__CLASS__, 'webplayer_shortcode']);

		add_shortcode('podlove-episode-web-player', [__CLASS__, 'webplayer_shortcode']);

		if ( defined( 'PODLOVEWEBPLAYER_DIR' ) ) {
			define( 'PODLOVE_MEDIA_PLAYER', 'external' );
			return;
		} else {
			define( 'PODLOVE_MEDIA_PLAYER', 'internal' );
		}

		include_once 'player/podlove-web-player/podlove-web-player.php';
	}

	/**
	 * Provides shortcode to display web player.
	 *
	 * Right now there is only audio support.
	 *
	 * Usage:
	 * 	[podlove-episode-web-player]
	 * 
	 */
	public static function webplayer_shortcode( $options ) {
		global $post;

		if ( is_feed() )
			return '';

		$episode = Model\Episode::find_or_create_by_post_id( $post->ID );
		$printer = new \Podlove\Modules\PodloveWebPlayer\Printer( $episode );
		return $printer->render();
	}

	public function standalone_player_page() {

		if (!isset($_GET['standalonePlayer']))
			return;

		if (!is_single())
			return;

		if (!$episode = Episode::find_or_create_by_post_id(get_the_ID()))
			return;

		?>
<!DOCTYPE html>
    <head>
        <script type="text/javascript" src="<?php echo $this->get_module_url() ?>/js/html5shiv.js"></script>
        <script type="text/javascript" src="<?php echo $this->get_module_url() ?>/js/jquery-1.9.1.min.js"></script>
        <script type="text/javascript" src="<?php echo $this->get_module_url() ?>/player/podlove-web-player/static/podlove-web-player.js"></script>
        <link rel="stylesheet" href="<?php echo $this->get_module_url() ?>/player/podlove-web-player/static/podlove-web-player.css" />
    </head>
    <body>
	    <?php
	    $printer = new Printer($episode);
	    echo $printer->render();
	    ?>
    </body>
</html>
		<?php
		exit;
	}

	public function autoinsert_into_content( $content ) {

		if ( get_post_type() !== 'podcast' || post_password_required() )
			return $content;

		if ( self::there_is_a_player_in_the_content( $content ) )
			return $content;

		$inject = \Podlove\get_webplayer_setting( 'inject' );

		if ( $inject == 'beginning' ) {
			$content = '[podlove-episode-web-player]' . $content;
		} elseif ( $inject == 'end' ) {
			$content = $content . '[podlove-episode-web-player]';
		}

		return $content;
	}

	public function register_meta_boxes() {
		add_meta_box(
			\Podlove\Podcast_Post_Type::SETTINGS_PAGE_HANDLE . '_player',
			__( 'Webplayer', 'podlove' ),
			array( $this, 'about_player_meta_box' ),
			\Podlove\Podcast_Post_Type::SETTINGS_PAGE_HANDLE,
			'side'
		);
	}

	public function about_player_meta_box() {
		if ( PODLOVE_MEDIA_PLAYER === 'external' )
			echo __( 'It looks like you have installed an <strong>external plugin</strong> using mediaelement.js.<br>That\'s what\'s used.', 'podlove' );
		else
			echo __( 'Podlove ships with its <strong>own webplayer</strong>.<br>That\'s what\'s used.', 'podlove' );
	}

	public static function there_is_a_player_in_the_content( $content ) {
		return (
			stripos( $content, '[podloveaudio' ) !== false OR 
			stripos( $content, '[podlovevideo' ) !== false OR
			stripos( $content, '[audio' ) !== false OR 
			stripos( $content, '[video' ) !== false OR
			stripos( $content, '[podlove-web-player' ) !== false OR
			stripos( $content, '[podlove-episode-web-player' ) !== false OR
			stripos( $content, '[podlove-template' ) !== false
		);
	}

}

