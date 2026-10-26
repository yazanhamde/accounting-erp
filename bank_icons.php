<?php
/**
 * أيقونات مصغّرة (SVG) لكل بنك أردني بالنظام - شكل موحّد (دائرة ملونة بحرف/رمز مميز)
 * بدل الإيموجي العام 🏦، بلون خاص بكل بنك يطابق ألوان الكروت (bank-blue/red/green/yellow/purple)
 */
function bank_icon($key, $size = 22) {
    $icons = [
        // البنك الأردني - درع أزرق بحرف "أ" (شخصي فاتح / مؤسسة غامق لتمييزهم بصرياً)
        'jordan' => ['bg' => '#2563eb', 'label' => 'أ'],
        'jordan_personal' => ['bg' => '#60a5fa', 'label' => 'أش'],
        'jordan_business' => ['bg' => '#1e3a8a', 'label' => 'أم'],
        // بنك الاتحاد - درع عنابي بحرف "ا"
        'etihad' => ['bg' => '#b91c1c', 'label' => 'إ'],
        // البنك الإسلامي - درع أخضر بهلال
        'islami' => ['bg' => '#15803d', 'label' => '☾'],
        // محفظة دينارك - دائرة برتقالية برمز محفظة
        'dinarak' => ['bg' => '#d97706', 'label' => 'د'],
        // البنك الاستثماري - دائرة بنفسجية برمز نمو
        'investment' => ['bg' => '#7e22ce', 'label' => '↗'],
    ];
    $icon = $icons[$key] ?? ['bg' => '#64748b', 'label' => '🏦'];
    $bg = $icon['bg'];
    $label = htmlspecialchars($icon['label']);
    return <<<SVG
<svg width="{$size}" height="{$size}" viewBox="0 0 22 22" style="vertical-align:-5px; margin-left:4px;">
    <circle cx="11" cy="11" r="11" fill="{$bg}"></circle>
    <text x="11" y="15" text-anchor="middle" font-size="11" font-family="Tahoma, Arial, sans-serif" fill="#fff" font-weight="bold">{$label}</text>
</svg>
SVG;
}
