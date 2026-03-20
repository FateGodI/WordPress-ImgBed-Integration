<?php
/**
 * Plugin Name: Cloudflare ImgBed Integration
 * Plugin URI:  https://github.com/FateGodI/WordPress-ImgBed-Integration
 * Description: 将图片同步到 Cloudflare ImgBed，提供三种本地文件保留策略，完美兼容子比主题。
 * Version:     2.1.0
 * Author:      zkeeolo
 * Author URI:  https://github.com/FateGodI/
 * License:     MIT
 * Text Domain: cf-imgbed
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CF_IMGBED_VERSION', '2.1.0' );
define( 'CF_IMGBED_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CF_IMGBED_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

class CF_ImgBed_Integration {

    // 文件保留策略常量
    const STRATEGY_KEEP_ALL      = 'keep_all';      // 保留所有本地文件
    const STRATEGY_KEEP_THUMBS   = 'keep_thumbs';   // 仅保留缩略图
    const STRATEGY_CLOUD_ONLY    = 'cloud_only';    // 完全云端，删除所有本地文件

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'plugins_loaded', array( $this, 'init' ) );
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
    }

    public function init() {
        load_plugin_textdomain( 'cf-imgbed', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'add_attachment', array( $this, 'sync_attachment_to_cf' ) );
        add_action( 'admin_post_cf_imgbed_manual_sync', array( $this, 'manual_sync_handler' ) );
        add_filter( 'attachment_fields_to_edit', array( $this, 'add_sync_button_to_media_edit' ), 10, 2 );

        // 替换图片 URL（无论策略如何，只要上传成功就替换）
        add_filter( 'wp_get_attachment_url', array( $this, 'replace_attachment_url' ), 10, 2 );
        add_filter( 'wp_get_attachment_image_src', array( $this, 'replace_attachment_image_src' ), 10, 4 );
        add_filter( 'the_content', array( $this, 'replace_content_image_urls' ) );

        // 重要：替换媒体库（后台）中的图片 URL，确保预览可见
        add_filter( 'wp_prepare_attachment_for_js', array( $this, 'prepare_attachment_for_js' ), 10, 3 );

        // 前端上传短代码
        add_shortcode( 'cf_upload_form', array( $this, 'render_upload_form' ) );
        add_action( 'wp_ajax_cf_imgbed_upload', array( $this, 'ajax_upload_handler' ) );
        add_action( 'wp_ajax_nopriv_cf_imgbed_upload', array( $this, 'ajax_upload_handler' ) );

        // 批量同步操作
        add_filter( 'bulk_actions-upload', array( $this, 'add_bulk_sync_action' ) );
        add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_sync' ), 10, 3 );
        add_action( 'admin_notices', array( $this, 'bulk_sync_notices' ) );
    }

    public function activate() {
        $defaults = array(
            'api_endpoint'          => '',
            'auth_type'             => 'authCode',
            'auth_value'            => '',
            'default_channel'       => 'telegram',
            'upload_folder'         => '',
            'return_format'         => 'full',
            'upload_name_type'      => 'default',
            'auto_retry'            => 'yes',
            'replace_url'           => 'yes',          // 默认启用URL替换 一般情况不需要修改！
            'local_strategy'        => self::STRATEGY_KEEP_ALL,
        );
        foreach ( $defaults as $key => $value ) {
            if ( false === get_option( 'cf_imgbed_' . $key ) ) {
                update_option( 'cf_imgbed_' . $key, $value );
            }
        }
    }

    public function deactivate() {}

    public function add_admin_menu() {
        add_options_page(
            __( 'Cloudflare ImgBed 设置', 'cf-imgbed' ),
            __( 'Cloudflare ImgBed', 'cf-imgbed' ),
            'manage_options',
            'cf-imgbed-settings',
            array( $this, 'settings_page' )
        );
    }

    public function register_settings() {
        register_setting( 'cf_imgbed_settings', 'cf_imgbed_api_endpoint', 'esc_url_raw' );
        register_setting( 'cf_imgbed_settings', 'cf_imgbed_auth_type', 'sanitize_text_field' );
        register_setting( 'cf_imgbed_settings', 'cf_imgbed_auth_value', 'sanitize_text_field' );
        register_setting( 'cf_imgbed_settings', 'cf_imgbed_default_channel', 'sanitize_text_field' );
        register_setting( 'cf_imgbed_settings', 'cf_imgbed_upload_folder', 'sanitize_text_field' );
        register_setting( 'cf_imgbed_settings', 'cf_imgbed_return_format', 'sanitize_text_field' );
        register_setting( 'cf_imgbed_settings', 'cf_imgbed_upload_name_type', 'sanitize_text_field' );
        register_setting( 'cf_imgbed_settings', 'cf_imgbed_auto_retry', 'sanitize_text_field' );
        register_setting( 'cf_imgbed_settings', 'cf_imgbed_replace_url', 'sanitize_text_field' );
        register_setting( 'cf_imgbed_settings', 'cf_imgbed_local_strategy', 'sanitize_text_field' );
    }

    public function settings_page() {
        $strategy = get_option( 'cf_imgbed_local_strategy', self::STRATEGY_KEEP_ALL );
        ?>
        <div class="wrap">
            <h1><?php _e( 'Cloudflare ImgBed 集成设置', 'cf-imgbed' ); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'cf_imgbed_settings' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e( 'API 端点 URL', 'cf-imgbed' ); ?></th>
                        <td>
                            <input type="url" name="cf_imgbed_api_endpoint" value="<?php echo esc_attr( get_option( 'cf_imgbed_api_endpoint' ) ); ?>" class="regular-text" required />
                            <p class="description"><?php _e( '例如: https://your.domain/upload', 'cf-imgbed' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e( '认证方式', 'cf-imgbed' ); ?></th>
                        <td>
                            <select name="cf_imgbed_auth_type">
                                <option value="authCode" <?php selected( get_option( 'cf_imgbed_auth_type' ), 'authCode' ); ?>><?php _e( '上传认证码 (authCode)', 'cf-imgbed' ); ?></option>
                                <option value="token" <?php selected( get_option( 'cf_imgbed_auth_type' ), 'token' ); ?>><?php _e( 'API Token (Bearer)', 'cf-imgbed' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e( '认证值', 'cf-imgbed' ); ?></th>
                        <td>
                            <input type="text" name="cf_imgbed_auth_value" value="<?php echo esc_attr( get_option( 'cf_imgbed_auth_value' ) ); ?>" class="regular-text" required />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e( '默认上传渠道', 'cf-imgbed' ); ?></th>
                        <td>
                            <input type="text" name="cf_imgbed_default_channel" value="<?php echo esc_attr( get_option( 'cf_imgbed_default_channel' ) ); ?>" />
                            <p class="description"><?php _e( '可选: telegram, cfr2, s3, discord, huggingface', 'cf-imgbed' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e( '上传目录', 'cf-imgbed' ); ?></th>
                        <td>
                            <input type="text" name="cf_imgbed_upload_folder" value="<?php echo esc_attr( get_option( 'cf_imgbed_upload_folder' ) ); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e( '返回链接格式', 'cf-imgbed' ); ?></th>
                        <td>
                            <select name="cf_imgbed_return_format">
                                <option value="full" <?php selected( get_option( 'cf_imgbed_return_format' ), 'full' ); ?>><?php _e( '完整链接 (full)', 'cf-imgbed' ); ?></option>
                                <option value="default" <?php selected( get_option( 'cf_imgbed_return_format' ), 'default' ); ?>><?php _e( '相对路径 (default)', 'cf-imgbed' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e( '文件命名方式', 'cf-imgbed' ); ?></th>
                        <td>
                            <select name="cf_imgbed_upload_name_type">
                                <option value="default" <?php selected( get_option( 'cf_imgbed_upload_name_type' ), 'default' ); ?>><?php _e( '默认前缀_原名', 'cf-imgbed' ); ?></option>
                                <option value="index" <?php selected( get_option( 'cf_imgbed_upload_name_type' ), 'index' ); ?>><?php _e( '仅前缀命名', 'cf-imgbed' ); ?></option>
                                <option value="origin" <?php selected( get_option( 'cf_imgbed_upload_name_type' ), 'origin' ); ?>><?php _e( '仅原名命名', 'cf-imgbed' ); ?></option>
                                <option value="short" <?php selected( get_option( 'cf_imgbed_upload_name_type' ), 'short' ); ?>><?php _e( '短链接命名法', 'cf-imgbed' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e( '失败自动重试', 'cf-imgbed' ); ?></th>
                        <td>
                            <input type="checkbox" name="cf_imgbed_auto_retry" value="yes" <?php checked( get_option( 'cf_imgbed_auto_retry' ), 'yes' ); ?> />
                            <label><?php _e( '上传失败时自动切换渠道重试', 'cf-imgbed' ); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e( '替换图片 URL', 'cf-imgbed' ); ?></th>
                        <td>
                            <input type="checkbox" name="cf_imgbed_replace_url" value="yes" <?php checked( get_option( 'cf_imgbed_replace_url' ), 'yes' ); ?> />
                            <label><?php _e( '启用后，所有已同步图片在前端将使用 Cloudflare ImgBed 链接', 'cf-imgbed' ); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e( '本地文件保留策略', 'cf-imgbed' ); ?></th>
                        <td>
                            <select name="cf_imgbed_local_strategy">
                                <option value="<?php echo self::STRATEGY_KEEP_ALL; ?>" <?php selected( $strategy, self::STRATEGY_KEEP_ALL ); ?>>
                                    <?php _e( '保留所有本地文件（默认）', 'cf-imgbed' ); ?>
                                </option>
                                <option value="<?php echo self::STRATEGY_KEEP_THUMBS; ?>" <?php selected( $strategy, self::STRATEGY_KEEP_THUMBS ); ?>>
                                    <?php _e( '仅保留缩略图（删除原图）', 'cf-imgbed' ); ?>
                                </option>
                                <option value="<?php echo self::STRATEGY_CLOUD_ONLY; ?>" <?php selected( $strategy, self::STRATEGY_CLOUD_ONLY ); ?>>
                                    <?php _e( '完全云端（删除所有本地文件）', 'cf-imgbed' ); ?>
                                </option>
                            </select>
                            <p class="description">
                                <?php _e( '注意：完全云端模式下，所有本地文件将被删除，仅通过云端链接显示图片，请确保同步成功后再启用。', 'cf-imgbed' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * 将附件同步到 Cloudflare ImgBed
     */
    public function sync_attachment_to_cf( $attachment_id ) {
        // 避免重复同步
        if ( get_post_meta( $attachment_id, '_cf_imgbed_url', true ) ) {
            return;
        }

        $file_path = get_attached_file( $attachment_id );
        if ( ! file_exists( $file_path ) ) {
            return;
        }

        $mime_type = get_post_mime_type( $attachment_id );
        $filename  = basename( $file_path );

        $result = $this->upload_to_cf( $file_path, $filename, $mime_type );
        if ( $result && isset( $result['src'] ) ) {
            update_post_meta( $attachment_id, '_cf_imgbed_url', $result['src'] );
            if ( isset( $result['channel'] ) ) {
                update_post_meta( $attachment_id, '_cf_imgbed_channel', $result['channel'] );
            }

            // 根据策略删除本地文件
            $strategy = get_option( 'cf_imgbed_local_strategy', self::STRATEGY_KEEP_ALL );
            $this->delete_local_files_by_strategy( $attachment_id, $file_path, $strategy );
        } else {
            error_log( 'CF ImgBed 上传失败，附件ID: ' . $attachment_id . ', 错误: ' . ( $result['error'] ?? '未知错误' ) );
        }
    }

    /**
     * 根据策略删除本地文件（鉴于2.0.1不执行重新修改）
     *
     * @param int    $attachment_id
     * @param string $original_path  get_attached_file() 返回的完整路径
     * @param string $strategy
     */
    private function delete_local_files_by_strategy( $attachment_id, $original_path, $strategy ) {
        if ( $strategy === self::STRATEGY_KEEP_ALL ) {
            return; // 不删除任何文件
        }

        // 获取附件元数据（用于缩略图）
        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( empty( $metadata ) ) {
            // 若元数据缺失，只尝试删除原图
            $this->maybe_delete_file( $original_path );
            return;
        }

        // 原图直接使用传入的路径，避免重复构建
        $original_file = $original_path;

        if ( $strategy === self::STRATEGY_KEEP_THUMBS ) {
            // 仅删除原图，保留所有缩略图
            if ( file_exists( $original_file ) ) {
                $deleted = $this->maybe_delete_file( $original_file );
                if ( $deleted ) {
                    update_post_meta( $attachment_id, '_cf_imgbed_original_deleted', 'yes' );
                } else {
                    error_log( "CF ImgBed: 原图删除失败 (ID {$attachment_id})：{$original_file}" );
                }
            }
        } elseif ( $strategy === self::STRATEGY_CLOUD_ONLY ) {
            // 删除原图
            $original_deleted = false;
            if ( file_exists( $original_file ) ) {
                $original_deleted = $this->maybe_delete_file( $original_file );
                if ( ! $original_deleted ) {
                    error_log( "CF ImgBed: 原图删除失败 (ID {$attachment_id})：{$original_file}" );
                }
            }

            // 删除所有缩略图
            $thumb_deleted_count = 0;
            if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
                $dir = trailingslashit( dirname( $original_file ) );
                foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                    if ( ! empty( $size_data['file'] ) ) {
                        $thumb_path = $dir . $size_data['file'];
                        if ( file_exists( $thumb_path ) ) {
                            if ( $this->maybe_delete_file( $thumb_path ) ) {
                                $thumb_deleted_count++;
                            } else {
                                error_log( "CF ImgBed: 缩略图删除失败 (ID {$attachment_id}, 尺寸 {$size_name})：{$thumb_path}" );
                            }
                        }
                    }
                }
            }

            // 只要删除了原图或至少一张缩略图，就记录云端标记
            if ( $original_deleted || $thumb_deleted_count > 0 ) {
                update_post_meta( $attachment_id, '_cf_imgbed_all_deleted', 'yes' );
            }
        }
    }

    /**
     * 安全删除文件，返回是否成功
     *
     * @param string $file_path
     * @return bool
     */
    private function maybe_delete_file( $file_path ) {
        if ( ! file_exists( $file_path ) ) {
            return true; // 文件已不存在，视为成功
        }
        // wp_delete_file 返回 true/false 取决于 unlink 是否成功
        return wp_delete_file( $file_path );
    }

    /**
     * 上传到 Cloudflare ImgBed（非分块）
     */
    private function upload_to_cf( $file_path, $filename, $mime_type ) {
        $api_endpoint = get_option( 'cf_imgbed_api_endpoint' );
        if ( empty( $api_endpoint ) ) {
            return false;
        }

        $query_args = array();
        $auth_type = get_option( 'cf_imgbed_auth_type' );
        $auth_val  = get_option( 'cf_imgbed_auth_value' );
        if ( $auth_type === 'authCode' ) {
            $query_args['authCode'] = $auth_val;
        }

        $query_args['serverCompress']   = 'true';
        $query_args['uploadChannel']    = get_option( 'cf_imgbed_default_channel', 'telegram' );
        $query_args['autoRetry']        = ( get_option( 'cf_imgbed_auto_retry' ) === 'yes' ) ? 'true' : 'false';
        $query_args['uploadNameType']   = get_option( 'cf_imgbed_upload_name_type', 'default' );
        $query_args['returnFormat']     = get_option( 'cf_imgbed_return_format', 'full' );

        $folder = get_option( 'cf_imgbed_upload_folder' );
        if ( ! empty( $folder ) ) {
            $query_args['uploadFolder'] = $folder;
        }

        $url = add_query_arg( $query_args, $api_endpoint );

        $file_content = file_get_contents( $file_path );
        if ( false === $file_content ) {
            return false;
        }

        $boundary = wp_generate_password( 24, false );
        $body = $this->build_multipart_body( $boundary, $filename, $mime_type, $file_content );

        $headers = array(
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            'User-Agent'   => 'WordPress CF ImgBed Integration/' . CF_IMGBED_VERSION,
        );

        if ( $auth_type === 'token' && ! empty( $auth_val ) ) {
            $headers['Authorization'] = 'Bearer ' . $auth_val;
        }

        $response = wp_remote_post( $url, array(
            'headers' => $headers,
            'body'    => $body,
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'error' => $response->get_error_message() );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code !== 200 ) {
            return array( 'error' => "HTTP $status_code: " . wp_remote_retrieve_body( $response ) );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) || ! isset( $data[0]['src'] ) ) {
            return array( 'error' => '无效响应格式: ' . $body );
        }

        $src = $data[0]['src'];
        if ( get_option( 'cf_imgbed_return_format' ) === 'default' ) {
            $parsed = parse_url( $api_endpoint );
            $domain = $parsed['scheme'] . '://' . $parsed['host'];
            $src = $domain . $src;
        }

        return array( 'src' => $src, 'channel' => $data[0]['channel'] ?? '' );
    }

    private function build_multipart_body( $boundary, $filename, $mime_type, $file_content ) {
        $body = '';
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . "\r\n";
        $body .= 'Content-Type: ' . $mime_type . "\r\n\r\n";
        $body .= $file_content . "\r\n";
        $body .= '--' . $boundary . "--\r\n";
        return $body;
    }

    /**
     * 替换附件主 URL
     */
    public function replace_attachment_url( $url, $attachment_id ) {
        if ( get_option( 'cf_imgbed_replace_url' ) !== 'yes' ) {
            return $url;
        }
        $cf_url = get_post_meta( $attachment_id, '_cf_imgbed_url', true );
        if ( ! empty( $cf_url ) ) {
            return $cf_url;
        }
        return $url;
    }

    /**
     * 替换图片源地址（用于缩略图）
     */
    public function replace_attachment_image_src( $image, $attachment_id, $size, $icon ) {
        if ( get_option( 'cf_imgbed_replace_url' ) !== 'yes' ) {
            return $image;
        }
        $cf_url = get_post_meta( $attachment_id, '_cf_imgbed_url', true );
        if ( ! empty( $cf_url ) && is_array( $image ) && isset( $image[0] ) ) {
            $image[0] = $cf_url;
        }
        return $image;
    }

    /**
     * 替换文章内容中的图片 URL
     */
    public function replace_content_image_urls( $content ) {
        if ( get_option( 'cf_imgbed_replace_url' ) !== 'yes' ) {
            return $content;
        }
        if ( ! is_singular() ) {
            return $content;
        }
        $content = preg_replace_callback( '/<img[^>]+src=["\']([^"\']+)["\']/i', array( $this, 'replace_img_src_callback' ), $content );
        return $content;
    }

    private function replace_img_src_callback( $matches ) {
        $original_src = $matches[1];
        $attachment_id = attachment_url_to_postid( $original_src );
        if ( $attachment_id ) {
            $cf_url = get_post_meta( $attachment_id, '_cf_imgbed_url', true );
            if ( $cf_url ) {
                return str_replace( $original_src, $cf_url, $matches[0] );
            }
        }
        return $matches[0];
    }

    /**
     * 替换媒体库（后台）中的图片 URL，确保预览可见（旧版有奇奇怪怪问题）
     *
     * @param array   $response  准备返回给媒体库的数据
     * @param WP_Post $post      附件对象
     * @param array   $meta      附件元数据
     * @return array
     */
    public function prepare_attachment_for_js( $response, $post, $meta ) {
        if ( get_option( 'cf_imgbed_replace_url' ) !== 'yes' ) {
            return $response;
        }

        $cf_url = get_post_meta( $post->ID, '_cf_imgbed_url', true );
        if ( empty( $cf_url ) ) {
            return $response;
        }

        // 替换主 URL
        $response['url'] = $cf_url;

        // 替换所有尺寸的 URL（如缩略图、中等、大图等）
        if ( isset( $response['sizes'] ) && is_array( $response['sizes'] ) ) {
            foreach ( $response['sizes'] as $size_name => &$size_data ) {
                if ( isset( $size_data['url'] ) ) {
                    $size_data['url'] = $cf_url;
                }
            }
        }

        // 替换图标 URL（如果有）
        if ( isset( $response['icon'] ) ) {
            $response['icon'] = $cf_url;
        }

        return $response;
    }

    /**
     * 媒体编辑页面添加按钮和状态
     */
    public function add_sync_button_to_media_edit( $form_fields, $post ) {
        $url = get_post_meta( $post->ID, '_cf_imgbed_url', true );
        $original_deleted = get_post_meta( $post->ID, '_cf_imgbed_original_deleted', true );
        $all_deleted = get_post_meta( $post->ID, '_cf_imgbed_all_deleted', true );
        $status = '';
        if ( $all_deleted ) {
            $status = '<span style="color:red;">（所有本地文件已删除，仅云端）</span>';
        } elseif ( $original_deleted ) {
            $status = '<span style="color:orange;">（本地原图已删除，仅保留缩略图）</span>';
        }

        if ( ! $url ) {
            $form_fields['cf_sync'] = array(
                'label' => __( 'Cloudflare ImgBed', 'cf-imgbed' ),
                'input' => 'html',
                'html'  => '<a href="' . esc_url( admin_url( 'admin-post.php?action=cf_imgbed_manual_sync&attachment_id=' . $post->ID . '&_wpnonce=' . wp_create_nonce( 'cf_sync_' . $post->ID ) ) ) . '" class="button">' . __( '同步到 Cloudflare ImgBed', 'cf-imgbed' ) . '</a> ' . $status,
            );
        } else {
            $form_fields['cf_sync'] = array(
                'label' => __( 'Cloudflare ImgBed 链接', 'cf-imgbed' ),
                'input' => 'html',
                'html'  => '<input type="text" value="' . esc_attr( $url ) . '" readonly class="large-text" /><br /><a href="' . esc_url( admin_url( 'admin-post.php?action=cf_imgbed_manual_sync&attachment_id=' . $post->ID . '&_wpnonce=' . wp_create_nonce( 'cf_sync_' . $post->ID ) ) ) . '" class="button">' . __( '重新同步', 'cf-imgbed' ) . '</a> ' . $status,
            );
        }
        return $form_fields;
    }

    public function manual_sync_handler() {
        if ( empty( $_GET['attachment_id'] ) || empty( $_GET['_wpnonce'] ) ) {
            wp_die( __( '非法请求', 'cf-imgbed' ) );
        }
        $attachment_id = intval( $_GET['attachment_id'] );
        if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'cf_sync_' . $attachment_id ) ) {
            wp_die( __( '安全验证失败', 'cf-imgbed' ) );
        }
        delete_post_meta( $attachment_id, '_cf_imgbed_url' );
        $this->sync_attachment_to_cf( $attachment_id );
        wp_safe_redirect( wp_get_referer() );
        exit;
    }

    /**
     * 前端上传短代码
     */
    public function render_upload_form( $atts ) {
        if ( ! is_user_logged_in() && ! current_user_can( 'upload_files' ) ) {
            return '<p>' . __( '请登录后上传', 'cf-imgbed' ) . '</p>';
        }

        ob_start();
        ?>
        <div id="cf-upload-form">
            <form id="cf-upload-file-form" method="post" enctype="multipart/form-data">
                <input type="file" name="cf_file" id="cf_file" accept="image/*" required />
                <button type="submit" id="cf-upload-submit"><?php _e( '上传到 Cloudflare ImgBed', 'cf-imgbed' ); ?></button>
                <div id="cf-upload-result"></div>
                <?php wp_nonce_field( 'cf_upload_nonce', 'cf_upload_nonce' ); ?>
            </form>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('#cf-upload-file-form').on('submit', function(e) {
                e.preventDefault();
                var formData = new FormData(this);
                formData.append('action', 'cf_imgbed_upload');
                $('#cf-upload-submit').prop('disabled', true).text('上传中...');
                $('#cf-upload-result').html('').removeClass('success error');
                $.ajax({
                    url: '<?php echo admin_url( 'admin-ajax.php' ); ?>',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        if (response.success) {
                            $('#cf-upload-result').html('<p class="success">上传成功！图片链接：<input type="text" value="'+response.data.url+'" readonly style="width:100%" /></p>').addClass('success');
                        } else {
                            $('#cf-upload-result').html('<p class="error">错误：'+response.data.message+'</p>').addClass('error');
                        }
                    },
                    error: function() {
                        $('#cf-upload-result').html('<p class="error">请求失败，请稍后重试。</p>').addClass('error');
                    },
                    complete: function() {
                        $('#cf-upload-submit').prop('disabled', false).text('上传到 Cloudflare ImgBed');
                    }
                });
            });
        });
        </script>
        <style>
        #cf-upload-form .success { color: green; margin-top: 10px; }
        #cf-upload-form .error { color: red; margin-top: 10px; }
        #cf-upload-form input[type="file"] { margin-bottom: 10px; }
        </style>
        <?php
        return ob_get_clean();
    }

    public function ajax_upload_handler() {
        if ( ! isset( $_POST['cf_upload_nonce'] ) || ! wp_verify_nonce( $_POST['cf_upload_nonce'], 'cf_upload_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( '安全验证失败', 'cf-imgbed' ) ) );
        }

        if ( ! isset( $_FILES['cf_file'] ) || $_FILES['cf_file']['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( array( 'message' => __( '文件上传失败', 'cf-imgbed' ) ) );
        }

        $file = $_FILES['cf_file'];
        $tmp_path = $file['tmp_name'];
        $filename = sanitize_file_name( $file['name'] );
        $mime_type = $file['type'];

        $result = $this->upload_to_cf( $tmp_path, $filename, $mime_type );
        if ( $result && isset( $result['src'] ) ) {
            wp_send_json_success( array( 'url' => $result['src'] ) );
        } else {
            $error_msg = isset( $result['error'] ) ? $result['error'] : __( '上传到 Cloudflare 失败', 'cf-imgbed' );
            wp_send_json_error( array( 'message' => $error_msg ) );
        }
    }

    public function add_bulk_sync_action( $bulk_actions ) {
        $bulk_actions['cf_sync_bulk'] = __( '同步到 Cloudflare ImgBed', 'cf-imgbed' );
        return $bulk_actions;
    }

    public function handle_bulk_sync( $redirect_to, $doaction, $post_ids ) {
        if ( $doaction !== 'cf_sync_bulk' ) {
            return $redirect_to;
        }
        $synced = 0;
        foreach ( $post_ids as $post_id ) {
            delete_post_meta( $post_id, '_cf_imgbed_url' );
            $this->sync_attachment_to_cf( $post_id );
            if ( get_post_meta( $post_id, '_cf_imgbed_url', true ) ) {
                $synced++;
            }
        }
        $redirect_to = add_query_arg( 'cf_synced', $synced, $redirect_to );
        return $redirect_to;
    }

    public function bulk_sync_notices() {
        if ( ! empty( $_REQUEST['cf_synced'] ) ) {
            $count = intval( $_REQUEST['cf_synced'] );
            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( __( '已成功同步 %d 个图片到 Cloudflare ImgBed', 'cf-imgbed' ), $count ) . '</p></div>';
        }
    }
}

CF_ImgBed_Integration::get_instance();
