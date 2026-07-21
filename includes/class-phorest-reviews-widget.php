<?php
/**
 * Native WordPress widget for the Atelier landing-page review surface.
 *
 * Available under Appearance → Widgets as "Phorest Reviews — Landing Widget".
 * Page builders can use [phorest_reviews_home] instead; both render the same
 * Atelier surface and pull from the same last-good cache.
 *
 * @package PhorestReviews
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

class Phorest_Reviews_Widget extends WP_Widget
{
    public function __construct()
    {
        parent::__construct(
            'phorest_reviews_landing',
            'Phorest Reviews — Landing Widget',
            [
                'description' => 'Atelier-themed verified Phorest reviews for the landing page.',
                'classname'   => 'widget_phorest_reviews_landing',
            ]
        );
    }

    /**
     * Frontend widget output.
     *
     * @param array $args     Theme widget wrappers.
     * @param array $instance Saved widget settings.
     */
    public function widget($args, $instance): void
    {
        $count      = max(1, min(6, (int) ($instance['count'] ?? 3)));
        $min_rating = max(1, min(5, (int) ($instance['min_rating'] ?? 4)));

        echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo Phorest_Reviews_Render::shortcode_homepage_strip([
            'count'      => $count,
            'min_rating' => $min_rating,
        ]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Widget admin form.
     *
     * @param array $instance Saved widget settings.
     */
    public function form($instance): void
    {
        $count      = max(1, min(6, (int) ($instance['count'] ?? 3)));
        $min_rating = max(1, min(5, (int) ($instance['min_rating'] ?? 4)));
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('count')); ?>">Reviews shown</label>
            <input class="tiny-text" type="number" min="1" max="6"
                id="<?php echo esc_attr($this->get_field_id('count')); ?>"
                name="<?php echo esc_attr($this->get_field_name('count')); ?>"
                value="<?php echo esc_attr((string) $count); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('min_rating')); ?>">Minimum rating</label>
            <select id="<?php echo esc_attr($this->get_field_id('min_rating')); ?>"
                name="<?php echo esc_attr($this->get_field_name('min_rating')); ?>">
                <?php for ($rating = 5; $rating >= 1; $rating--): ?>
                    <option value="<?php echo esc_attr((string) $rating); ?>" <?php selected($min_rating, $rating); ?>>
                        <?php echo esc_html($rating . ' star' . (1 === $rating ? '' : 's') . ' & up'); ?>
                    </option>
                <?php endfor; ?>
            </select>
        </p>
        <?php
    }

    /**
     * Sanitize widget settings.
     *
     * @param array $new_instance Submitted settings.
     * @param array $old_instance Previous settings.
     * @return array
     */
    public function update($new_instance, $old_instance): array
    {
        return [
            'count'      => max(1, min(6, (int) ($new_instance['count'] ?? 3))),
            'min_rating' => max(1, min(5, (int) ($new_instance['min_rating'] ?? 4))),
        ];
    }
}
