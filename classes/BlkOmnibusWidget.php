<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

/**
 * Elementor widget showing the Omnibus-directive pre-reduction price
 * disclosure for the current product. The disclosed amount is always the
 * product's base (regular) price - not the sale price and not the tracked
 * 30-day price history.
 *
 * Drop it into the single product template or a product Loop Item
 * template (Elementor Theme Builder) - it resolves the product from the
 * current post, so it works in both contexts.
 */
class BlkOmnibusWidget extends Widget_Base {

    const WPML_CONTEXT     = 'blk-synchronizer-main';
    const WPML_STRING_NAME = 'omnibus_widget_label';
    const DEFAULT_LABEL    = 'Najniższa cena z 30 dni przed obniżką:';

    public function get_name() {
        return 'blk-omnibus-price';
    }

    public function get_title() {
        return 'BLK Omnibus Price';
    }

    public function get_icon() {
        return 'eicon-product-price';
    }

    public function get_categories() {
        return [ 'woocommerce-elements', 'general' ];
    }

    protected function register_controls() {
        $this->start_controls_section( 'section_content', [
            'label' => 'Content',
        ]);

        $this->add_control( 'label_text', [
            'label'       => 'Label (override)',
            'type'        => Controls_Manager::TEXT,
            'default'     => '',
            'placeholder' => self::DEFAULT_LABEL,
            'description' => 'Zostaw puste, aby użyć tekstu tłumaczonego automatycznie przez WPML String Translation (kontekst "' . self::WPML_CONTEXT . '", string "' . self::WPML_STRING_NAME . '").',
        ]);

        $this->end_controls_section();

        $this->start_controls_section( 'section_style', [
            'label' => 'Style',
            'tab'   => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_control( 'text_color', [
            'label'     => 'Text Color',
            'type'      => Controls_Manager::COLOR,
            'default'   => '#6d6d6d',
            'selectors' => [
                '{{WRAPPER}} .blk-omnibus-price' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_group_control( Group_Control_Typography::get_type(), [
            'name'     => 'typography',
            'selector' => '{{WRAPPER}} .blk-omnibus-price',
        ]);

        $this->end_controls_section();
    }

    protected function render() {
        $product_id = get_the_ID();
        if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {
            return;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product || $product->get_sale_price() === '' ) {
            return;
        }

        // Always disclose the product's base (regular) price - never the
        // current sale price and never the tracked price history. The
        // regular price is by definition the higher of the two, which is
        // what we want to show as the pre-reduction reference price.
        $base_price = $product->get_regular_price();
        if ( $base_price === '' || $base_price === null ) {
            return;
        }

        $settings = $this->get_settings_for_display();
        $label    = ! empty( $settings['label_text'] ) ? $settings['label_text'] : $this->get_translated_label();

        printf(
            '<div class="blk-omnibus-price">%s %s</div>',
            esc_html( $label ),
            wp_kses_post( wc_price( floatval( $base_price ) ) )
        );
    }

    /**
     * Resolve the widget's default label through WPML String Translation
     * (registers it as a translatable string on first call so it shows up
     * in WPML → String Translation, and returns the translation for the
     * current site language when one exists).
     */
    private function get_translated_label() {
        if ( ! has_action( 'wpml_register_single_string' ) ) {
            return self::DEFAULT_LABEL;
        }

        do_action( 'wpml_register_single_string', self::WPML_CONTEXT, self::WPML_STRING_NAME, self::DEFAULT_LABEL );

        return apply_filters( 'wpml_translate_single_string', self::DEFAULT_LABEL, self::WPML_CONTEXT, self::WPML_STRING_NAME );
    }
}
