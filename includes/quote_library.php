<?php
/**
 * Built-in quote library — public-domain / fair-use seed content that
 * practitioners can search and star into their personal references.
 *
 * Coverage is intentionally broad: religious texts (Bible OT/NT, Quran,
 * Tao Te Ching, Bhagavad Gita, Dhammapada, Talmud, Sufi tradition, Sikh
 * scripture), Stoic philosophy, existentialism, Christian mystics,
 * recovery / 12-step, mindfulness, grief / lament, and modern wisdom
 * literature whose authors are out of copyright.
 *
 * Each entry:
 *   id        — stable string key (so star records stay valid across
 *               library edits)
 *   body      — the quote text itself
 *   author    — speaker / writer (or 'Anonymous' / tradition name)
 *   year      — best estimate, may be approximate (e.g. 'c. 6th c. BCE')
 *   source    — book / chapter / verse / publication
 *   tags      — themes — used by the search filter
 */

if (!function_exists('quote_library_all')) {

/**
 * Top-level categories used to organise the library. Practitioners can also
 * filter by tag (themes like grief, anxiety, cbt) which cuts across categories.
 *
 * "Practitioner" is reserved for items the practitioner created themselves;
 * library seeds never carry that category.
 */
function quote_library_categories(): array {
    return [
        'Christian',
        'Jewish',
        'Islamic',
        'Buddhist',
        'Hindu',
        'Taoist',
        'Interfaith',
        'Philosophy',
        'Mindfulness',
        'Recovery',
        'Grief',
        'Pop Culture',
        'Practitioner',
    ];
}

/**
 * Resolves a single library entry's primary category from its id.
 * Hebrew Bible content sits under 'Interfaith' (used by Jewish AND Christian
 * practitioners alike); New Testament under 'Christian'; Talmud / Pirkei Avot
 * under 'Jewish'; etc. Library content is never categorised as 'Practitioner'
 * — that label is reserved for user-created entries.
 */
function quote_library_category_for(string $id): string {
    static $map = null;
    if ($map === null) {
        $map = [
            // Hebrew Bible — shared scripture; primary category Interfaith.
            'bible_psalm_23_1' => 'Interfaith', 'bible_psalm_46_10' => 'Interfaith',
            'bible_eccl_3_1' => 'Interfaith',  'bible_isaiah_40_31' => 'Interfaith',
            'bible_isaiah_43_2' => 'Interfaith','bible_proverbs_4_23' => 'Interfaith',
            'bible_micah_6_8' => 'Interfaith',  'bible_lamentations_3_22' => 'Interfaith',
            'bible_jeremiah_29_11' => 'Interfaith',
            // New Testament — Christian.
            'bible_matthew_5_4' => 'Christian', 'bible_matthew_11_28' => 'Christian',
            'bible_john_8_32' => 'Christian',   'bible_romans_8_28' => 'Christian',
            'bible_1_cor_13_4' => 'Christian',  'bible_phil_4_6' => 'Christian',
            'bible_2_tim_1_7' => 'Christian',
            // Christian mystics.
            'julian_norwich_well' => 'Christian',     'augustine_restless_heart' => 'Christian',
            'meister_eckhart_thank_you' => 'Christian','teresa_avila_let_nothing' => 'Christian',
            // Quran + Sufi.
            'quran_2_286' => 'Islamic', 'quran_94_5' => 'Islamic',
            'quran_13_28' => 'Islamic', 'quran_49_13' => 'Islamic',
            'quran_2_153' => 'Islamic', 'quran_5_32' => 'Islamic',
            'rumi_wound_light' => 'Islamic', 'rumi_guest_house' => 'Islamic',
            'hafiz_dropping_keys' => 'Islamic',
            // Tao.
            'tao_te_ching_33' => 'Taoist', 'tao_te_ching_64' => 'Taoist',
            'tao_te_ching_8' => 'Taoist',  'tao_te_ching_22' => 'Taoist',
            // Hindu.
            'gita_2_47' => 'Hindu', 'gita_6_5' => 'Hindu',
            'upanishads_tat_tvam_asi' => 'Hindu', 'upanishads_lead_me' => 'Hindu',
            // Buddhist.
            'dhammapada_1_1' => 'Buddhist', 'dhammapada_5_67' => 'Buddhist',
            'buddha_pain_suffering' => 'Buddhist',
            'dhammapada_3_50' => 'Buddhist', 'metta_sutta_loving' => 'Buddhist',
            'thich_nhat_hanh_present' => 'Mindfulness',
            // Jewish — Talmud / Pirkei Avot.
            'talmud_pirkei_avot_2_16' => 'Jewish', 'talmud_sanhedrin_37a' => 'Jewish',
            'hillel_pirkei_avot_1_14' => 'Jewish',
            'pirkei_avot_2_5' => 'Jewish', 'pirkei_avot_4_1' => 'Jewish',
            // Sikh, Bahá'í, Indigenous — Interfaith bucket (cross-tradition).
            'guru_nanak_truth' => 'Interfaith',
            'bahaullah_unity' => 'Interfaith', 'bahaullah_companion' => 'Interfaith',
            'ubuntu_proverb' => 'Interfaith', 'lakota_proverb_relations' => 'Interfaith',
            'maori_proverb_strength' => 'Interfaith', 'iroquois_seven_generations' => 'Interfaith',
            // Stoic + Existential + classic philosophy.
            'aurelius_meditations_4_3' => 'Philosophy', 'aurelius_meditations_2_1' => 'Philosophy',
            'epictetus_enchiridion_5' => 'Philosophy',  'seneca_lucilius_long_life' => 'Philosophy',
            'nietzsche_meaning_how' => 'Philosophy',     'nietzsche_what_doesnt_kill' => 'Philosophy',
            'nietzsche_become_who_you_are' => 'Philosophy', 'nietzsche_dancing_star' => 'Philosophy',
            'nietzsche_abyss' => 'Philosophy',           'nietzsche_no_facts' => 'Philosophy',
            'kierkegaard_anxiety' => 'Philosophy',
            'kierkegaard_understood' => 'Philosophy',    'thoreau_walden_lives' => 'Philosophy',
            'emerson_self_reliance' => 'Philosophy',
            // Modern wisdom — Frankl, Rogers, Jung, Sagan, Einstein.
            'frankl_meaning' => 'Philosophy',  'frankl_freedom' => 'Philosophy',
            'rogers_paradox' => 'Philosophy',  'jung_shadow' => 'Philosophy',
            'sagan_pale_blue_dot' => 'Philosophy', 'einstein_rage_mystery' => 'Philosophy',
            // Recovery.
            'niebuhr_serenity_prayer' => 'Recovery', 'aa_one_day_at_a_time' => 'Recovery',
            'aa_progress_perfection' => 'Recovery', 'aa_we_admitted' => 'Recovery',
            'maya_angelou_when_better' => 'Recovery',
            // Grief.
            'cs_lewis_grief_fear' => 'Grief',
            'gibran_pain_understanding' => 'Grief', 'gibran_joy_sorrow' => 'Grief',
            'mr_rogers_helpers' => 'Grief',
            // Pop Culture.
            'st_picard_no_mistakes' => 'Pop Culture', 'st_picard_line_must_be_drawn' => 'Pop Culture',
            'st_picard_things_impossible' => 'Pop Culture', 'st_data_humanity' => 'Pop Culture',
            'st_jack_crusher_lost' => 'Pop Culture', 'st_liam_shaw_chicago' => 'Pop Culture',
            'st_archer_in_this_together' => 'Pop Culture', 'st_seven_humanity' => 'Pop Culture',
            'st_janeway_mistakes' => 'Pop Culture', 'st_riker_define_us' => 'Pop Culture',
            'st_troi_pain_defines' => 'Pop Culture', 'st_sisko_perseverance' => 'Pop Culture',
            'st_bashir_we_choose' => 'Pop Culture', 'st_garak_truth_imagination' => 'Pop Culture',
            'st_picard_make_it_so' => 'Pop Culture',
            'lotr_gandalf_time_given' => 'Pop Culture', 'lotr_galadriel_smallest' => 'Pop Culture',
            'lotr_gandalf_tears' => 'Pop Culture', 'lotr_sam_worth_fighting' => 'Pop Culture',
            'lotr_gandalf_despair' => 'Pop Culture', 'fellowship_pain_of_loss' => 'Pop Culture',
            'sw_yoda_do_or_do_not' => 'Pop Culture', 'sw_yoda_fear' => 'Pop Culture',
            'sw_yoda_failure_teacher' => 'Pop Culture', 'sw_obiwan_truths' => 'Pop Culture',
            'shawshank_hope' => 'Pop Culture', 'shawshank_get_busy' => 'Pop Culture',
            'dead_poets_carpe_diem' => 'Pop Culture', 'good_will_hunting_not_your_fault' => 'Pop Culture',
            'matrix_path' => 'Pop Culture', 'wonderful_life_friends' => 'Pop Culture',
            'avatar_iroh_tunnel' => 'Pop Culture', 'ted_lasso_be_curious' => 'Pop Culture',
        ];
    }
    return $map[$id] ?? 'Interfaith';
}

function quote_library_all(): array {
    $entries = quote_library_entries_raw();
    foreach ($entries as &$e) {
        $e['category'] = quote_library_category_for($e['id']);
    }
    unset($e);
    return $entries;
}

function quote_library_entries_raw(): array {
    return [

        // ═══════════════════════════════════════════════════════════
        // BIBLE — OLD TESTAMENT
        // ═══════════════════════════════════════════════════════════
        [
            'id'    => 'bible_psalm_23_1',
            'body'  => 'The Lord is my shepherd; I shall not want. He maketh me to lie down in green pastures: he leadeth me beside the still waters. He restoreth my soul.',
            'author'=> 'Psalmist',
            'year'  => 'c. 10th–6th c. BCE',
            'source'=> 'Bible — Psalm 23:1–3 (KJV)',
            'tags'  => ['pastoral','grief','comfort','faith','christian','jewish'],
        ],
        [
            'id'    => 'bible_psalm_46_10',
            'body'  => 'Be still, and know that I am God.',
            'author'=> 'Psalmist',
            'year'  => 'c. 10th–6th c. BCE',
            'source'=> 'Bible — Psalm 46:10 (KJV)',
            'tags'  => ['pastoral','mindfulness','stillness','faith','christian','jewish'],
        ],
        [
            'id'    => 'bible_eccl_3_1',
            'body'  => 'To every thing there is a season, and a time to every purpose under the heaven: a time to be born, and a time to die; a time to plant, and a time to pluck up that which is planted.',
            'author'=> 'Qoheleth',
            'year'  => 'c. 3rd c. BCE',
            'source'=> 'Bible — Ecclesiastes 3:1–2 (KJV)',
            'tags'  => ['pastoral','grief','transition','meaning','christian','jewish'],
        ],
        [
            'id'    => 'bible_isaiah_40_31',
            'body'  => 'But they that wait upon the Lord shall renew their strength; they shall mount up with wings as eagles; they shall run, and not be weary; and they shall walk, and not faint.',
            'author'=> 'Isaiah',
            'year'  => 'c. 8th c. BCE',
            'source'=> 'Bible — Isaiah 40:31 (KJV)',
            'tags'  => ['pastoral','recovery','endurance','faith','christian','jewish'],
        ],
        [
            'id'    => 'bible_isaiah_43_2',
            'body'  => 'When thou passest through the waters, I will be with thee; and through the rivers, they shall not overflow thee: when thou walkest through the fire, thou shalt not be burned; neither shall the flame kindle upon thee.',
            'author'=> 'Isaiah',
            'year'  => 'c. 6th c. BCE',
            'source'=> 'Bible — Isaiah 43:2 (KJV)',
            'tags'  => ['pastoral','trauma','crisis','faith','christian','jewish'],
        ],
        [
            'id'    => 'bible_proverbs_4_23',
            'body'  => 'Keep thy heart with all diligence; for out of it are the issues of life.',
            'author'=> 'Proverbs',
            'year'  => 'c. 7th c. BCE',
            'source'=> 'Bible — Proverbs 4:23 (KJV)',
            'tags'  => ['pastoral','wisdom','self-care','christian','jewish'],
        ],
        [
            'id'    => 'bible_micah_6_8',
            'body'  => 'He hath shewed thee, O man, what is good; and what doth the Lord require of thee, but to do justly, and to love mercy, and to walk humbly with thy God?',
            'author'=> 'Micah',
            'year'  => 'c. 8th c. BCE',
            'source'=> 'Bible — Micah 6:8 (KJV)',
            'tags'  => ['pastoral','meaning','ethics','christian','jewish'],
        ],
        [
            'id'    => 'bible_lamentations_3_22',
            'body'  => 'It is of the Lord\'s mercies that we are not consumed, because his compassions fail not. They are new every morning: great is thy faithfulness.',
            'author'=> 'Jeremiah',
            'year'  => 'c. 6th c. BCE',
            'source'=> 'Bible — Lamentations 3:22–23 (KJV)',
            'tags'  => ['pastoral','grief','hope','christian','jewish'],
        ],
        [
            'id'    => 'bible_jeremiah_29_11',
            'body'  => 'For I know the thoughts that I think toward you, saith the Lord, thoughts of peace, and not of evil, to give you an expected end.',
            'author'=> 'Jeremiah',
            'year'  => 'c. 6th c. BCE',
            'source'=> 'Bible — Jeremiah 29:11 (KJV)',
            'tags'  => ['pastoral','hope','meaning','christian','jewish'],
        ],

        // ═══════════════════════════════════════════════════════════
        // BIBLE — NEW TESTAMENT
        // ═══════════════════════════════════════════════════════════
        [
            'id'    => 'bible_matthew_5_4',
            'body'  => 'Blessed are they that mourn: for they shall be comforted.',
            'author'=> 'Jesus of Nazareth',
            'year'  => 'c. 30 CE',
            'source'=> 'Bible — Matthew 5:4 (KJV)',
            'tags'  => ['pastoral','grief','comfort','christian'],
        ],
        [
            'id'    => 'bible_matthew_11_28',
            'body'  => 'Come unto me, all ye that labour and are heavy laden, and I will give you rest.',
            'author'=> 'Jesus of Nazareth',
            'year'  => 'c. 30 CE',
            'source'=> 'Bible — Matthew 11:28 (KJV)',
            'tags'  => ['pastoral','rest','comfort','christian'],
        ],
        [
            'id'    => 'bible_john_8_32',
            'body'  => 'And ye shall know the truth, and the truth shall make you free.',
            'author'=> 'Jesus of Nazareth',
            'year'  => 'c. 30 CE',
            'source'=> 'Bible — John 8:32 (KJV)',
            'tags'  => ['pastoral','truth','freedom','christian'],
        ],
        [
            'id'    => 'bible_romans_8_28',
            'body'  => 'And we know that all things work together for good to them that love God, to them who are the called according to his purpose.',
            'author'=> 'Paul of Tarsus',
            'year'  => 'c. 57 CE',
            'source'=> 'Bible — Romans 8:28 (KJV)',
            'tags'  => ['pastoral','meaning','hope','christian'],
        ],
        [
            'id'    => 'bible_1_cor_13_4',
            'body'  => 'Charity suffereth long, and is kind; charity envieth not; charity vaunteth not itself, is not puffed up, doth not behave itself unseemly, seeketh not her own, is not easily provoked, thinketh no evil; rejoiceth not in iniquity, but rejoiceth in the truth.',
            'author'=> 'Paul of Tarsus',
            'year'  => 'c. 55 CE',
            'source'=> 'Bible — 1 Corinthians 13:4–6 (KJV)',
            'tags'  => ['pastoral','love','relationships','christian'],
        ],
        [
            'id'    => 'bible_phil_4_6',
            'body'  => 'Be careful for nothing; but in every thing by prayer and supplication with thanksgiving let your requests be made known unto God. And the peace of God, which passeth all understanding, shall keep your hearts and minds.',
            'author'=> 'Paul of Tarsus',
            'year'  => 'c. 62 CE',
            'source'=> 'Bible — Philippians 4:6–7 (KJV)',
            'tags'  => ['pastoral','anxiety','peace','christian'],
        ],
        [
            'id'    => 'bible_2_tim_1_7',
            'body'  => 'For God hath not given us the spirit of fear; but of power, and of love, and of a sound mind.',
            'author'=> 'Paul of Tarsus',
            'year'  => 'c. 65 CE',
            'source'=> 'Bible — 2 Timothy 1:7 (KJV)',
            'tags'  => ['pastoral','anxiety','courage','christian'],
        ],

        // ═══════════════════════════════════════════════════════════
        // QURAN
        // ═══════════════════════════════════════════════════════════
        [
            'id'    => 'quran_2_286',
            'body'  => 'Allah does not burden a soul beyond that it can bear.',
            'author'=> 'Quran',
            'year'  => 'c. 610–632 CE',
            'source'=> 'Quran — Surah Al-Baqarah 2:286',
            'tags'  => ['pastoral','muslim','endurance','crisis','faith'],
        ],
        [
            'id'    => 'quran_94_5',
            'body'  => 'So verily, with hardship comes ease. Verily, with hardship comes ease.',
            'author'=> 'Quran',
            'year'  => 'c. 610–622 CE',
            'source'=> 'Quran — Surah Ash-Sharh 94:5–6',
            'tags'  => ['pastoral','muslim','hope','crisis','faith'],
        ],
        [
            'id'    => 'quran_13_28',
            'body'  => 'Verily, in the remembrance of Allah do hearts find rest.',
            'author'=> 'Quran',
            'year'  => 'c. 610–632 CE',
            'source'=> 'Quran — Surah Ar-Ra\'d 13:28',
            'tags'  => ['pastoral','muslim','peace','mindfulness','faith'],
        ],
        [
            'id'    => 'quran_49_13',
            'body'  => 'O mankind, indeed We have created you from male and female and made you peoples and tribes that you may know one another.',
            'author'=> 'Quran',
            'year'  => 'c. 622–632 CE',
            'source'=> 'Quran — Surah Al-Hujurat 49:13',
            'tags'  => ['pastoral','muslim','community','identity','faith'],
        ],

        // ═══════════════════════════════════════════════════════════
        // TAO TE CHING
        // ═══════════════════════════════════════════════════════════
        [
            'id'    => 'tao_te_ching_33',
            'body'  => 'Knowing others is intelligence; knowing yourself is true wisdom. Mastering others is strength; mastering yourself is true power.',
            'author'=> 'Lao Tzu',
            'year'  => 'c. 6th c. BCE',
            'source'=> 'Tao Te Ching, Chapter 33',
            'tags'  => ['philosophical','taoist','self-knowledge','wisdom'],
        ],
        [
            'id'    => 'tao_te_ching_64',
            'body'  => 'A journey of a thousand miles begins with a single step.',
            'author'=> 'Lao Tzu',
            'year'  => 'c. 6th c. BCE',
            'source'=> 'Tao Te Ching, Chapter 64',
            'tags'  => ['philosophical','taoist','beginnings','recovery','goals'],
        ],
        [
            'id'    => 'tao_te_ching_8',
            'body'  => 'The supreme good is like water, which nourishes all things without trying to. It is content with the low places that people disdain. Thus it is like the Tao.',
            'author'=> 'Lao Tzu',
            'year'  => 'c. 6th c. BCE',
            'source'=> 'Tao Te Ching, Chapter 8',
            'tags'  => ['philosophical','taoist','humility','flow'],
        ],
        [
            'id'    => 'tao_te_ching_22',
            'body'  => 'Yield and overcome; bend and be straight; empty and be full.',
            'author'=> 'Lao Tzu',
            'year'  => 'c. 6th c. BCE',
            'source'=> 'Tao Te Ching, Chapter 22',
            'tags'  => ['philosophical','taoist','paradox','acceptance'],
        ],

        // ═══════════════════════════════════════════════════════════
        // BHAGAVAD GITA
        // ═══════════════════════════════════════════════════════════
        [
            'id'    => 'gita_2_47',
            'body'  => 'You have a right to perform your prescribed duties, but you are not entitled to the fruits of your actions. Never consider yourself to be the cause of the results of your activities, nor be attached to inaction.',
            'author'=> 'Bhagavad Gita',
            'year'  => 'c. 2nd c. BCE',
            'source'=> 'Bhagavad Gita 2:47',
            'tags'  => ['philosophical','hindu','action','non-attachment'],
        ],
        [
            'id'    => 'gita_6_5',
            'body'  => 'One must elevate — not degrade — oneself by one\'s own mind. The mind is the friend of the conditioned soul, and his enemy as well.',
            'author'=> 'Bhagavad Gita',
            'year'  => 'c. 2nd c. BCE',
            'source'=> 'Bhagavad Gita 6:5',
            'tags'  => ['philosophical','hindu','mind','self-mastery'],
        ],

        // ═══════════════════════════════════════════════════════════
        // BUDDHIST — DHAMMAPADA
        // ═══════════════════════════════════════════════════════════
        [
            'id'    => 'dhammapada_1_1',
            'body'  => 'All that we are is the result of what we have thought. The mind is everything. What we think we become.',
            'author'=> 'The Buddha',
            'year'  => 'c. 5th c. BCE',
            'source'=> 'Dhammapada 1:1',
            'tags'  => ['mindfulness','buddhist','mind','cbt'],
        ],
        [
            'id'    => 'dhammapada_5_67',
            'body'  => 'Hatred does not cease by hatred, but only by love; this is the eternal rule.',
            'author'=> 'The Buddha',
            'year'  => 'c. 5th c. BCE',
            'source'=> 'Dhammapada 5:67',
            'tags'  => ['mindfulness','buddhist','forgiveness','relationships'],
        ],
        [
            'id'    => 'buddha_pain_suffering',
            'body'  => 'Pain is inevitable, suffering is optional.',
            'author'=> 'The Buddha (attributed)',
            'year'  => 'c. 5th c. BCE',
            'source'=> 'Buddhist tradition',
            'tags'  => ['mindfulness','buddhist','grief','acceptance'],
        ],

        // ═══════════════════════════════════════════════════════════
        // TALMUD / JEWISH TRADITION
        // ═══════════════════════════════════════════════════════════
        [
            'id'    => 'talmud_pirkei_avot_2_16',
            'body'  => 'You are not obligated to complete the work, but neither are you free to abandon it.',
            'author'=> 'Rabbi Tarfon',
            'year'  => 'c. 100 CE',
            'source'=> 'Pirkei Avot 2:16 (Talmud)',
            'tags'  => ['pastoral','jewish','meaning','responsibility','recovery'],
        ],
        [
            'id'    => 'talmud_sanhedrin_37a',
            'body'  => 'Whoever saves a single life is considered by Scripture to have saved the whole world.',
            'author'=> 'Talmud',
            'year'  => 'c. 200–500 CE',
            'source'=> 'Talmud — Sanhedrin 37a',
            'tags'  => ['pastoral','jewish','meaning','peer-support'],
        ],
        [
            'id'    => 'hillel_pirkei_avot_1_14',
            'body'  => 'If I am not for myself, who will be for me? If I am only for myself, what am I? And if not now, when?',
            'author'=> 'Hillel the Elder',
            'year'  => 'c. 1st c. BCE',
            'source'=> 'Pirkei Avot 1:14',
            'tags'  => ['pastoral','jewish','identity','agency'],
        ],

        // ═══════════════════════════════════════════════════════════
        // SUFI / RUMI / HAFIZ
        // ═══════════════════════════════════════════════════════════
        [
            'id'    => 'rumi_wound_light',
            'body'  => 'The wound is the place where the Light enters you.',
            'author'=> 'Rumi',
            'year'  => 'c. 1250 CE',
            'source'=> 'Masnavi (attributed translation by Coleman Barks)',
            'tags'  => ['pastoral','sufi','grief','trauma','healing'],
        ],
        [
            'id'    => 'rumi_guest_house',
            'body'  => 'This being human is a guest house. Every morning a new arrival. A joy, a depression, a meanness, some momentary awareness comes as an unexpected visitor. Welcome and entertain them all!',
            'author'=> 'Rumi',
            'year'  => 'c. 1250 CE',
            'source'=> '"The Guest House", Masnavi',
            'tags'  => ['mindfulness','sufi','acceptance','emotion'],
        ],
        [
            'id'    => 'hafiz_dropping_keys',
            'body'  => 'The small man builds cages for everyone he knows. While the sage, who has to duck his head when the moon is low, keeps dropping keys all night long for the beautiful, rowdy prisoners.',
            'author'=> 'Hafiz',
            'year'  => 'c. 1380 CE',
            'source'=> 'The Gift (translation by Daniel Ladinsky)',
            'tags'  => ['philosophical','sufi','freedom','peer-support'],
        ],

        // ═══════════════════════════════════════════════════════════
        // SIKH / GURU GRANTH SAHIB
        // ═══════════════════════════════════════════════════════════
        [
            'id'    => 'guru_nanak_truth',
            'body'  => 'Truth is high, but higher still is truthful living.',
            'author'=> 'Guru Nanak',
            'year'  => 'c. 1500 CE',
            'source'=> 'Sri Guru Granth Sahib, Sri Rag M. 1',
            'tags'  => ['pastoral','sikh','truth','ethics'],
        ],

        // ═══════════════════════════════════════════════════════════
        // CHRISTIAN MYSTICS
        // ═══════════════════════════════════════════════════════════
        [
            'id'    => 'julian_norwich_well',
            'body'  => 'All shall be well, and all shall be well, and all manner of thing shall be well.',
            'author'=> 'Julian of Norwich',
            'year'  => 'c. 1395 CE',
            'source'=> 'Revelations of Divine Love',
            'tags'  => ['pastoral','christian','grief','hope','faith'],
        ],
        [
            'id'    => 'augustine_restless_heart',
            'body'  => 'Thou hast made us for thyself, O Lord, and our heart is restless until it finds its rest in thee.',
            'author'=> 'Augustine of Hippo',
            'year'  => '397 CE',
            'source'=> 'Confessions, Book I',
            'tags'  => ['pastoral','christian','meaning','restlessness'],
        ],
        [
            'id'    => 'meister_eckhart_thank_you',
            'body'  => 'If the only prayer you ever say in your entire life is thank you, it will be enough.',
            'author'=> 'Meister Eckhart',
            'year'  => 'c. 1300 CE',
            'source'=> 'Sermons',
            'tags'  => ['pastoral','christian','gratitude','prayer'],
        ],
        [
            'id'    => 'teresa_avila_let_nothing',
            'body'  => 'Let nothing disturb you. Let nothing frighten you. All things are passing. God alone is unchanging.',
            'author'=> 'Teresa of Ávila',
            'year'  => 'c. 1577 CE',
            'source'=> 'Prayers of Teresa of Ávila',
            'tags'  => ['pastoral','christian','anxiety','impermanence'],
        ],

        // ═══════════════════════════════════════════════════════════
        // STOIC PHILOSOPHY
        // ═══════════════════════════════════════════════════════════
        [
            'id'    => 'aurelius_meditations_4_3',
            'body'  => 'You have power over your mind — not outside events. Realize this, and you will find strength.',
            'author'=> 'Marcus Aurelius',
            'year'  => 'c. 165 CE',
            'source'=> 'Meditations, Book IV',
            'tags'  => ['philosophical','stoic','agency','cbt'],
        ],
        [
            'id'    => 'aurelius_meditations_2_1',
            'body'  => 'When you arise in the morning, think of what a precious privilege it is to be alive — to breathe, to think, to enjoy, to love.',
            'author'=> 'Marcus Aurelius',
            'year'  => 'c. 165 CE',
            'source'=> 'Meditations, Book II',
            'tags'  => ['philosophical','stoic','gratitude','mindfulness'],
        ],
        [
            'id'    => 'epictetus_enchiridion_5',
            'body'  => 'Men are disturbed not by the things which happen, but by the opinions about the things.',
            'author'=> 'Epictetus',
            'year'  => 'c. 125 CE',
            'source'=> 'Enchiridion 5',
            'tags'  => ['philosophical','stoic','cbt','reframing'],
        ],
        [
            'id'    => 'seneca_lucilius_long_life',
            'body'  => 'It is not that we have a short time to live, but that we waste a lot of it.',
            'author'=> 'Seneca',
            'year'  => 'c. 49 CE',
            'source'=> 'On the Shortness of Life',
            'tags'  => ['philosophical','stoic','meaning','time'],
        ],

        // ═══════════════════════════════════════════════════════════
        // EXISTENTIAL / PHILOSOPHICAL (out of copyright)
        // ═══════════════════════════════════════════════════════════
        [
            'id'    => 'nietzsche_meaning_how',
            'body'  => 'He who has a why to live for can bear almost any how.',
            'author'=> 'Friedrich Nietzsche',
            'year'  => '1889',
            'source'=> 'Twilight of the Idols, "Maxims and Arrows" §12',
            'tags'  => ['philosophical','existential','meaning','recovery'],
        ],
        [
            'id'    => 'nietzsche_what_doesnt_kill',
            'body'  => 'What does not kill me makes me stronger.',
            'author'=> 'Friedrich Nietzsche',
            'year'  => '1889',
            'source'=> 'Twilight of the Idols, "Maxims and Arrows" §8',
            'tags'  => ['philosophical','existential','recovery','endurance','trauma'],
        ],
        [
            'id'    => 'nietzsche_become_who_you_are',
            'body'  => 'Become who you are.',
            'author'=> 'Friedrich Nietzsche',
            'year'  => '1883',
            'source'=> 'Thus Spoke Zarathustra (drawing on Pindar)',
            'tags'  => ['philosophical','existential','authenticity','identity'],
        ],
        [
            'id'    => 'nietzsche_dancing_star',
            'body'  => 'One must still have chaos in oneself to be able to give birth to a dancing star.',
            'author'=> 'Friedrich Nietzsche',
            'year'  => '1883',
            'source'=> 'Thus Spoke Zarathustra, Prologue §5',
            'tags'  => ['philosophical','existential','creativity','growth','emotion'],
        ],
        [
            'id'    => 'nietzsche_abyss',
            'body'  => 'He who fights with monsters should look to it that he himself does not become a monster. And if you gaze long into an abyss, the abyss also gazes into you.',
            'author'=> 'Friedrich Nietzsche',
            'year'  => '1886',
            'source'=> 'Beyond Good and Evil §146',
            'tags'  => ['philosophical','existential','self-awareness','trauma'],
        ],
        [
            'id'    => 'nietzsche_no_facts',
            'body'  => 'There are no facts, only interpretations.',
            'author'=> 'Friedrich Nietzsche',
            'year'  => 'c. 1886–1887',
            'source'=> 'Notebooks (Nachlass), published posthumously',
            'tags'  => ['philosophical','existential','cbt','reframing'],
        ],
        [
            'id'    => 'kierkegaard_anxiety',
            'body'  => 'Anxiety is the dizziness of freedom.',
            'author'=> 'Søren Kierkegaard',
            'year'  => '1844',
            'source'=> 'The Concept of Anxiety',
            'tags'  => ['philosophical','existential','anxiety','freedom'],
        ],
        [
            'id'    => 'kierkegaard_understood',
            'body'  => 'Life can only be understood backwards; but it must be lived forwards.',
            'author'=> 'Søren Kierkegaard',
            'year'  => '1843',
            'source'=> 'Journals',
            'tags'  => ['philosophical','existential','meaning','time'],
        ],
        [
            'id'    => 'thoreau_walden_lives',
            'body'  => 'The mass of men lead lives of quiet desperation.',
            'author'=> 'Henry David Thoreau',
            'year'  => '1854',
            'source'=> 'Walden',
            'tags'  => ['philosophical','meaning','authenticity'],
        ],
        [
            'id'    => 'emerson_self_reliance',
            'body'  => 'To be yourself in a world that is constantly trying to make you something else is the greatest accomplishment.',
            'author'=> 'Ralph Waldo Emerson',
            'year'  => '1841',
            'source'=> 'Self-Reliance',
            'tags'  => ['philosophical','authenticity','identity'],
        ],

        // ═══════════════════════════════════════════════════════════
        // RECOVERY / 12-STEP / SERENITY
        // ═══════════════════════════════════════════════════════════
        [
            'id'    => 'niebuhr_serenity_prayer',
            'body'  => 'God grant me the serenity to accept the things I cannot change, courage to change the things I can, and wisdom to know the difference.',
            'author'=> 'Reinhold Niebuhr',
            'year'  => '1932',
            'source'=> 'Serenity Prayer',
            'tags'  => ['recovery','pastoral','acceptance','christian'],
        ],
        [
            'id'    => 'aa_one_day_at_a_time',
            'body'  => 'One day at a time.',
            'author'=> 'Alcoholics Anonymous tradition',
            'year'  => '1939',
            'source'=> 'AA Big Book',
            'tags'  => ['recovery','mindfulness','present-moment'],
        ],
        [
            'id'    => 'aa_progress_perfection',
            'body'  => 'We claim spiritual progress rather than spiritual perfection.',
            'author'=> 'Alcoholics Anonymous',
            'year'  => '1939',
            'source'=> 'AA Big Book, Chapter 5',
            'tags'  => ['recovery','progress','self-compassion'],
        ],

        // ═══════════════════════════════════════════════════════════
        // GRIEF / LAMENT
        // ═══════════════════════════════════════════════════════════
        [
            'id'    => 'cs_lewis_grief_fear',
            'body'  => 'No one ever told me that grief felt so like fear.',
            'author'=> 'C. S. Lewis',
            'year'  => '1961',
            'source'=> 'A Grief Observed',
            'tags'  => ['grief','christian','loss'],
        ],
        [
            'id'    => 'gibran_pain_understanding',
            'body'  => 'Your pain is the breaking of the shell that encloses your understanding.',
            'author'=> 'Kahlil Gibran',
            'year'  => '1923',
            'source'=> 'The Prophet, "On Pain"',
            'tags'  => ['grief','pastoral','growth','meaning'],
        ],
        [
            'id'    => 'gibran_joy_sorrow',
            'body'  => 'Your joy is your sorrow unmasked. The deeper that sorrow carves into your being, the more joy you can contain.',
            'author'=> 'Kahlil Gibran',
            'year'  => '1923',
            'source'=> 'The Prophet, "On Joy and Sorrow"',
            'tags'  => ['grief','pastoral','emotion'],
        ],

        // ═══════════════════════════════════════════════════════════
        // MINDFULNESS — CLASSIC
        // ═══════════════════════════════════════════════════════════
        [
            'id'    => 'thich_nhat_hanh_present',
            'body'  => 'The present moment is the only moment available to us, and it is the door to all moments.',
            'author'=> 'Thích Nhất Hạnh (attributed)',
            'year'  => 'c. 1975',
            'source'=> 'Buddhist tradition',
            'tags'  => ['mindfulness','buddhist','present-moment'],
        ],

        // ═══════════════════════════════════════════════════════════
        // MODERN WISDOM (where source is well-known/fair-use)
        // ═══════════════════════════════════════════════════════════
        [
            'id'    => 'frankl_meaning',
            'body'  => 'When we are no longer able to change a situation, we are challenged to change ourselves.',
            'author'=> 'Viktor Frankl',
            'year'  => '1946',
            'source'=> 'Man\'s Search for Meaning',
            'tags'  => ['existential','meaning','recovery','grief'],
        ],
        [
            'id'    => 'frankl_freedom',
            'body'  => 'Between stimulus and response there is a space. In that space is our power to choose our response. In our response lies our growth and our freedom.',
            'author'=> 'Viktor Frankl (attributed)',
            'year'  => '1946',
            'source'=> 'Tradition based on Man\'s Search for Meaning',
            'tags'  => ['existential','cbt','freedom','agency'],
        ],
        [
            'id'    => 'rogers_paradox',
            'body'  => 'The curious paradox is that when I accept myself just as I am, then I can change.',
            'author'=> 'Carl Rogers',
            'year'  => '1961',
            'source'=> 'On Becoming a Person',
            'tags'  => ['therapy','self-acceptance','growth'],
        ],
        [
            'id'    => 'jung_shadow',
            'body'  => 'There is no coming to consciousness without pain. People will do anything, no matter how absurd, in order to avoid facing their own soul.',
            'author'=> 'Carl Jung',
            'year'  => '1939',
            'source'=> 'Collected Works',
            'tags'  => ['therapy','existential','growth'],
        ],
        [
            'id'    => 'maya_angelou_when_better',
            'body'  => 'Do the best you can until you know better. Then when you know better, do better.',
            'author'=> 'Maya Angelou (attributed)',
            'year'  => 'c. 1980s',
            'source'=> 'Public talks',
            'tags'  => ['recovery','growth','self-compassion'],
        ],

        // ═══════════════════════════════════════════════════════════
        // PEER SUPPORT / COMMUNITY
        // ═══════════════════════════════════════════════════════════
        [
            'id'    => 'aa_we_admitted',
            'body'  => 'We are people who normally would not mix. But there exists among us a fellowship, a friendliness, and an understanding which is indescribably wonderful.',
            'author'=> 'Alcoholics Anonymous',
            'year'  => '1939',
            'source'=> 'AA Big Book, Foreword',
            'tags'  => ['recovery','peer-support','community'],
        ],
        [
            'id'    => 'mr_rogers_helpers',
            'body'  => 'When I was a boy and I would see scary things in the news, my mother would say to me, "Look for the helpers. You will always find people who are helping."',
            'author'=> 'Fred Rogers',
            'year'  => 'c. 1980s',
            'source'=> 'Public broadcast',
            'tags'  => ['peer-support','crisis','grief'],
        ],

        // ═══════════════════════════════════════════════════════════
        // AFRICAN / INDIGENOUS WISDOM TRADITIONS
        // ═══════════════════════════════════════════════════════════
        [
            'id'    => 'ubuntu_proverb',
            'body'  => 'I am because we are.',
            'author'=> 'Ubuntu philosophy',
            'year'  => 'African oral tradition',
            'source'=> 'Southern African Bantu tradition',
            'tags'  => ['philosophical','community','peer-support','identity'],
        ],
        [
            'id'    => 'lakota_proverb_relations',
            'body'  => 'Mitákuye Oyás\'iŋ — All are my relatives.',
            'author'=> 'Lakota tradition',
            'year'  => 'Indigenous oral tradition',
            'source'=> 'Lakota prayer tradition',
            'tags'  => ['pastoral','indigenous','community','interconnection'],
        ],
        [
            'id'    => 'maori_proverb_strength',
            'body'  => 'Mā mua ka kite a muri, mā muri ka ora a mua. Those who lead give sight to those who follow; those who follow give life to those who lead.',
            'author'=> 'Māori whakataukī',
            'year'  => 'Indigenous oral tradition',
            'source'=> 'Māori proverb',
            'tags'  => ['indigenous','community','peer-support','leadership'],
        ],
        [
            'id'    => 'iroquois_seven_generations',
            'body'  => 'In every deliberation, we must consider the impact on the seventh generation.',
            'author'=> 'Haudenosaunee (Iroquois) tradition',
            'year'  => 'Indigenous oral tradition',
            'source'=> 'Great Law of Peace',
            'tags'  => ['indigenous','meaning','responsibility','ethics'],
        ],

        // ═══════════════════════════════════════════════════════════
        // ADDITIONAL QURAN — broader coverage
        // ═══════════════════════════════════════════════════════════
        [
            'id'    => 'quran_2_153',
            'body'  => 'O you who have believed, seek help through patience and prayer. Indeed, Allah is with the patient.',
            'author'=> 'Quran',
            'year'  => 'c. 622–632 CE',
            'source'=> 'Quran — Surah Al-Baqarah 2:153',
            'tags'  => ['pastoral','muslim','patience','grief','crisis'],
        ],
        [
            'id'    => 'quran_5_32',
            'body'  => 'Whoever saves a life, it is as if he had saved all of mankind.',
            'author'=> 'Quran',
            'year'  => 'c. 622–632 CE',
            'source'=> 'Quran — Surah Al-Ma\'idah 5:32',
            'tags'  => ['pastoral','muslim','meaning','peer-support'],
        ],

        // ═══════════════════════════════════════════════════════════
        // ADDITIONAL BUDDHIST
        // ═══════════════════════════════════════════════════════════
        [
            'id'    => 'dhammapada_3_50',
            'body'  => 'Do not look at the faults of others, or what others have done or not done; observe what you yourself have done and have not done.',
            'author'=> 'The Buddha',
            'year'  => 'c. 5th c. BCE',
            'source'=> 'Dhammapada 3:50',
            'tags'  => ['mindfulness','buddhist','self-awareness','relationships'],
        ],
        [
            'id'    => 'metta_sutta_loving',
            'body'  => 'May all beings be happy. May all beings be free from suffering. May all beings be at peace.',
            'author'=> 'Buddhist tradition',
            'year'  => 'c. 5th c. BCE',
            'source'=> 'Metta Sutta (Loving-Kindness)',
            'tags'  => ['mindfulness','buddhist','compassion','prayer'],
        ],

        // ═══════════════════════════════════════════════════════════
        // ADDITIONAL HINDU / VEDIC / UPANISHADS
        // ═══════════════════════════════════════════════════════════
        [
            'id'    => 'upanishads_tat_tvam_asi',
            'body'  => 'Tat tvam asi — That thou art.',
            'author'=> 'Chandogya Upanishad',
            'year'  => 'c. 8th c. BCE',
            'source'=> 'Chandogya Upanishad 6.8.7',
            'tags'  => ['hindu','identity','philosophical'],
        ],
        [
            'id'    => 'upanishads_lead_me',
            'body'  => 'From the unreal lead me to the real. From darkness lead me to light. From death lead me to immortality.',
            'author'=> 'Brihadaranyaka Upanishad',
            'year'  => 'c. 7th c. BCE',
            'source'=> 'Brihadaranyaka Upanishad 1.3.28 (Pavamana Mantra)',
            'tags'  => ['hindu','prayer','meaning','faith'],
        ],

        // ═══════════════════════════════════════════════════════════
        // ADDITIONAL JEWISH / TALMUD
        // ═══════════════════════════════════════════════════════════
        [
            'id'    => 'pirkei_avot_2_5',
            'body'  => 'In a place where there are no human beings, strive to be human.',
            'author'=> 'Hillel the Elder',
            'year'  => 'c. 1st c. BCE',
            'source'=> 'Pirkei Avot 2:5',
            'tags'  => ['pastoral','jewish','ethics','identity'],
        ],
        [
            'id'    => 'pirkei_avot_4_1',
            'body'  => 'Who is wise? One who learns from every person. Who is strong? One who masters their own desires. Who is rich? One who is content with their portion. Who is honored? One who honors others.',
            'author'=> 'Ben Zoma',
            'year'  => 'c. 100 CE',
            'source'=> 'Pirkei Avot 4:1',
            'tags'  => ['pastoral','jewish','wisdom','self-mastery'],
        ],

        // ═══════════════════════════════════════════════════════════
        // BAHÁʼÍ
        // ═══════════════════════════════════════════════════════════
        [
            'id'    => 'bahaullah_unity',
            'body'  => 'The earth is but one country, and mankind its citizens.',
            'author'=> 'Bahá\'u\'lláh',
            'year'  => 'c. 1870 CE',
            'source'=> 'Tablets of Bahá\'u\'lláh',
            'tags'  => ['pastoral','bahai','community','identity'],
        ],
        [
            'id'    => 'bahaullah_companion',
            'body'  => 'Be generous in prosperity, and thankful in adversity. Be a treasure to the poor, an admonisher to the rich, an answerer of the cry of the needy.',
            'author'=> 'Bahá\'u\'lláh',
            'year'  => 'c. 1860 CE',
            'source'=> 'Hidden Words',
            'tags'  => ['pastoral','bahai','ethics','community'],
        ],

        // ═══════════════════════════════════════════════════════════
        // POP CULTURE — Star Trek
        // Carefully chosen for therapeutic context: meaning, struggle, what
        // it means to be human, perseverance, dignity. Attributions are to
        // the character who delivers the line, with episode/film source.
        // ═══════════════════════════════════════════════════════════
        [
            'id'    => 'st_picard_no_mistakes',
            'body'  => 'It is possible to commit no mistakes and still lose. That is not a weakness; that is life.',
            'author'=> 'Captain Jean-Luc Picard',
            'year'  => '1989',
            'source'=> 'Star Trek: The Next Generation — "Peak Performance"',
            'tags'  => ['popculture','startrek','meaning','self-compassion','recovery'],
        ],
        [
            'id'    => 'st_picard_line_must_be_drawn',
            'body'  => 'The line must be drawn here. This far, no further. And I will make them pay for what they\'ve done.',
            'author'=> 'Captain Jean-Luc Picard',
            'year'  => '1996',
            'source'=> 'Star Trek: First Contact (film)',
            'tags'  => ['popculture','startrek','agency','boundaries','trauma'],
        ],
        [
            'id'    => 'st_picard_things_impossible',
            'body'  => 'Things are only impossible until they\'re not.',
            'author'=> 'Captain Jean-Luc Picard',
            'year'  => '1989',
            'source'=> 'Star Trek: The Next Generation — "When the Bough Breaks"',
            'tags'  => ['popculture','startrek','hope','agency'],
        ],
        [
            'id'    => 'st_data_humanity',
            'body'  => 'I am superior, sir, in many ways. But I would gladly give it up to be human.',
            'author'=> 'Lt. Commander Data',
            'year'  => '1987',
            'source'=> 'Star Trek: The Next Generation — "Encounter at Farpoint"',
            'tags'  => ['popculture','startrek','identity','meaning','authenticity'],
        ],
        [
            'id'    => 'st_jack_crusher_lost',
            'body'  => 'We all long for connection. But we\'re just a little bit alone, aren\'t we? Stars in the same galaxy, but light-years between us.',
            'author'=> 'Jack Crusher',
            'year'  => '2023',
            'source'=> 'Star Trek: Picard — Season 3',
            'tags'  => ['popculture','startrek','connection','loneliness','meaning','identity','peer-support'],
        ],
        [
            'id'    => 'st_liam_shaw_chicago',
            'body'  => 'I\'m just a kid from Chicago who fixed his eyes on the stars.',
            'author'=> 'Captain Liam Shaw',
            'year'  => '2023',
            'source'=> 'Star Trek: Picard — Season 3, "No Win Scenario"',
            'tags'  => ['popculture','startrek','identity','dignity','meaning'],
        ],
        [
            'id'    => 'st_archer_in_this_together',
            'body'  => 'Whatever happens, we\'re in this together.',
            'author'=> 'Captain Jonathan Archer',
            'year'  => '2001',
            'source'=> 'Star Trek: Enterprise',
            'tags'  => ['popculture','startrek','community','peer-support'],
        ],
        [
            'id'    => 'st_seven_humanity',
            'body'  => 'You are who you choose to be. You don\'t have to be defined by what was done to you.',
            'author'=> 'Seven of Nine',
            'year'  => 'c. 2000',
            'source'=> 'Star Trek: Voyager (paraphrased recurring theme)',
            'tags'  => ['popculture','startrek','trauma','identity','recovery'],
        ],
        [
            'id'    => 'st_janeway_mistakes',
            'body'  => 'It\'s important to allow yourself to make mistakes — and important not to judge yourself too harshly when you do.',
            'author'=> 'Captain Kathryn Janeway',
            'year'  => 'c. 1996',
            'source'=> 'Star Trek: Voyager',
            'tags'  => ['popculture','startrek','self-compassion','recovery'],
        ],
        [
            'id'    => 'st_riker_define_us',
            'body'  => 'It\'s not what we are that defines us — it\'s what we do.',
            'author'=> 'Commander William Riker',
            'year'  => 'c. 1990',
            'source'=> 'Star Trek: The Next Generation (paraphrased recurring theme)',
            'tags'  => ['popculture','startrek','meaning','agency','identity'],
        ],
        [
            'id'    => 'st_troi_pain_defines',
            'body'  => 'The pain we go through is what defines us. Without it, we are nothing.',
            'author'=> 'Counselor Deanna Troi',
            'year'  => 'c. 1991',
            'source'=> 'Star Trek: The Next Generation',
            'tags'  => ['popculture','startrek','grief','meaning','growth'],
        ],
        [
            'id'    => 'st_sisko_perseverance',
            'body'  => 'It\'s easy to be a saint in paradise. The real test of one\'s soul comes in the dark.',
            'author'=> 'Captain Benjamin Sisko',
            'year'  => 'c. 1998',
            'source'=> 'Star Trek: Deep Space Nine (paraphrased)',
            'tags'  => ['popculture','startrek','endurance','meaning'],
        ],
        [
            'id'    => 'st_bashir_we_choose',
            'body'  => 'We can choose what we want to do. That choice — that\'s what makes us who we are.',
            'author'=> 'Doctor Julian Bashir',
            'year'  => 'c. 1997',
            'source'=> 'Star Trek: Deep Space Nine',
            'tags'  => ['popculture','startrek','agency','identity'],
        ],
        [
            'id'    => 'st_garak_truth_imagination',
            'body'  => 'The truth is usually just an excuse for a lack of imagination.',
            'author'=> 'Elim Garak',
            'year'  => '1995',
            'source'=> 'Star Trek: Deep Space Nine — "Improbable Cause"',
            'tags'  => ['popculture','startrek','reframing','cbt','truth'],
        ],
        [
            'id'    => 'st_picard_make_it_so',
            'body'  => 'Engage. Make it so.',
            'author'=> 'Captain Jean-Luc Picard',
            'year'  => '1987',
            'source'=> 'Star Trek: The Next Generation (recurring)',
            'tags'  => ['popculture','startrek','agency','goals'],
        ],

        // ═══════════════════════════════════════════════════════════
        // POP CULTURE — Lord of the Rings
        // ═══════════════════════════════════════════════════════════
        [
            'id'    => 'lotr_gandalf_time_given',
            'body'  => 'All we have to decide is what to do with the time that is given us.',
            'author'=> 'Gandalf',
            'year'  => '1954',
            'source'=> 'J. R. R. Tolkien — The Fellowship of the Ring',
            'tags'  => ['popculture','lotr','meaning','agency','time'],
        ],
        [
            'id'    => 'lotr_galadriel_smallest',
            'body'  => 'Even the smallest person can change the course of the future.',
            'author'=> 'Galadriel',
            'year'  => '2001',
            'source'=> 'The Lord of the Rings: The Fellowship of the Ring (Peter Jackson, after Tolkien)',
            'tags'  => ['popculture','lotr','meaning','agency','peer-support'],
        ],
        [
            'id'    => 'lotr_gandalf_tears',
            'body'  => 'I will not say: do not weep; for not all tears are an evil.',
            'author'=> 'Gandalf',
            'year'  => '1955',
            'source'=> 'J. R. R. Tolkien — The Return of the King',
            'tags'  => ['popculture','lotr','grief','emotion'],
        ],
        [
            'id'    => 'lotr_sam_worth_fighting',
            'body'  => 'There\'s some good in this world, Mr. Frodo. And it\'s worth fighting for.',
            'author'=> 'Samwise Gamgee',
            'year'  => '2002',
            'source'=> 'The Lord of the Rings: The Two Towers (Peter Jackson)',
            'tags'  => ['popculture','lotr','hope','meaning','peer-support'],
        ],
        [
            'id'    => 'lotr_gandalf_despair',
            'body'  => 'It is not despair, for despair is only for those who see the end beyond all doubt. We do not.',
            'author'=> 'Gandalf',
            'year'  => '1954',
            'source'=> 'J. R. R. Tolkien — The Two Towers',
            'tags'  => ['popculture','lotr','hope','grief'],
        ],

        // ═══════════════════════════════════════════════════════════
        // POP CULTURE — Star Wars
        // ═══════════════════════════════════════════════════════════
        [
            'id'    => 'sw_yoda_do_or_do_not',
            'body'  => 'Do or do not. There is no try.',
            'author'=> 'Yoda',
            'year'  => '1980',
            'source'=> 'Star Wars: The Empire Strikes Back',
            'tags'  => ['popculture','starwars','agency','goals','recovery'],
        ],
        [
            'id'    => 'sw_yoda_fear',
            'body'  => 'Fear is the path to the dark side. Fear leads to anger. Anger leads to hate. Hate leads to suffering.',
            'author'=> 'Yoda',
            'year'  => '1999',
            'source'=> 'Star Wars: The Phantom Menace',
            'tags'  => ['popculture','starwars','anxiety','emotion','cbt'],
        ],
        [
            'id'    => 'sw_yoda_failure_teacher',
            'body'  => 'The greatest teacher, failure is.',
            'author'=> 'Yoda',
            'year'  => '2017',
            'source'=> 'Star Wars: The Last Jedi',
            'tags'  => ['popculture','starwars','growth','self-compassion','recovery'],
        ],
        [
            'id'    => 'sw_obiwan_truths',
            'body'  => 'Many of the truths we cling to depend greatly on our own point of view.',
            'author'=> 'Obi-Wan Kenobi',
            'year'  => '1983',
            'source'=> 'Star Wars: Return of the Jedi',
            'tags'  => ['popculture','starwars','reframing','cbt','perspective'],
        ],

        // ═══════════════════════════════════════════════════════════
        // POP CULTURE — Other film & TV
        // ═══════════════════════════════════════════════════════════
        [
            'id'    => 'shawshank_hope',
            'body'  => 'Hope is a good thing, maybe the best of things, and no good thing ever dies.',
            'author'=> 'Andy Dufresne',
            'year'  => '1994',
            'source'=> 'The Shawshank Redemption (Frank Darabont)',
            'tags'  => ['popculture','film','hope','recovery','grief'],
        ],
        [
            'id'    => 'shawshank_get_busy',
            'body'  => 'Get busy living, or get busy dying.',
            'author'=> 'Andy Dufresne',
            'year'  => '1994',
            'source'=> 'The Shawshank Redemption (Frank Darabont)',
            'tags'  => ['popculture','film','agency','recovery','meaning'],
        ],
        [
            'id'    => 'dead_poets_carpe_diem',
            'body'  => 'Carpe diem. Seize the day, boys. Make your lives extraordinary.',
            'author'=> 'John Keating',
            'year'  => '1989',
            'source'=> 'Dead Poets Society (Tom Schulman)',
            'tags'  => ['popculture','film','meaning','agency','authenticity'],
        ],
        [
            'id'    => 'good_will_hunting_not_your_fault',
            'body'  => 'It\'s not your fault.',
            'author'=> 'Sean Maguire',
            'year'  => '1997',
            'source'=> 'Good Will Hunting (Matt Damon, Ben Affleck)',
            'tags'  => ['popculture','film','trauma','self-compassion','grief'],
        ],
        [
            'id'    => 'matrix_path',
            'body'  => 'There\'s a difference between knowing the path and walking the path.',
            'author'=> 'Morpheus',
            'year'  => '1999',
            'source'=> 'The Matrix (the Wachowskis)',
            'tags'  => ['popculture','film','agency','growth','recovery'],
        ],
        [
            'id'    => 'wonderful_life_friends',
            'body'  => 'No man is a failure who has friends.',
            'author'=> 'Clarence Odbody',
            'year'  => '1946',
            'source'=> 'It\'s a Wonderful Life (Frank Capra)',
            'tags'  => ['popculture','film','community','peer-support','meaning'],
        ],
        [
            'id'    => 'avatar_iroh_tunnel',
            'body'  => 'Sometimes life is like this dark tunnel. You can\'t always see the light at the end of the tunnel, but if you just keep moving, you will come to a better place.',
            'author'=> 'Uncle Iroh',
            'year'  => '2006',
            'source'=> 'Avatar: The Last Airbender — "The Earth King"',
            'tags'  => ['popculture','tv','grief','recovery','endurance','peer-support'],
        ],
        [
            'id'    => 'ted_lasso_be_curious',
            'body'  => 'Be curious, not judgmental.',
            'author'=> 'Ted Lasso (channeling Walt Whitman misattribution)',
            'year'  => '2021',
            'source'=> 'Ted Lasso — "The Hope That Kills You"',
            'tags'  => ['popculture','tv','reframing','relationships','self-compassion'],
        ],
        [
            'id'    => 'fellowship_pain_of_loss',
            'body'  => 'The world is indeed full of peril, and in it there are many dark places. But still there is much that is fair, and though in all lands love is now mingled with grief, it grows perhaps the greater.',
            'author'=> 'Haldir',
            'year'  => '1954',
            'source'=> 'J. R. R. Tolkien — The Fellowship of the Ring',
            'tags'  => ['popculture','lotr','grief','hope','meaning'],
        ],

        // ═══════════════════════════════════════════════════════════
        // SECULAR HUMANIST / NATURALIST
        // ═══════════════════════════════════════════════════════════
        [
            'id'    => 'sagan_pale_blue_dot',
            'body'  => 'Look again at that dot. That\'s here. That\'s home. That\'s us. On it everyone you love, everyone you know, everyone you ever heard of, every human being who ever was, lived out their lives.',
            'author'=> 'Carl Sagan',
            'year'  => '1994',
            'source'=> 'Pale Blue Dot',
            'tags'  => ['secular','meaning','perspective'],
        ],
        [
            'id'    => 'einstein_rage_mystery',
            'body'  => 'The most beautiful experience we can have is the mysterious. It is the fundamental emotion that stands at the cradle of true art and true science.',
            'author'=> 'Albert Einstein',
            'year'  => '1931',
            'source'=> 'The World as I See It',
            'tags'  => ['secular','meaning','wonder'],
        ],
    ];
}

/**
 * Search the library by query (matches author, body, source, tags).
 * @param string $q  Lowercase query
 * @return array filtered list
 */
function quote_library_search(string $q): array {
    $q = trim(mb_strtolower($q));
    $all = quote_library_all();
    if ($q === '') return $all;
    return array_values(array_filter($all, function($e) use ($q) {
        $hay = mb_strtolower(
            $e['body'] . ' ' .
            ($e['author'] ?? '') . ' ' .
            ($e['source'] ?? '') . ' ' .
            implode(' ', $e['tags'] ?? [])
        );
        return mb_strpos($hay, $q) !== false;
    }));
}

/**
 * Look up a single library entry by id (used when starring).
 */
function quote_library_get(string $id): ?array {
    foreach (quote_library_all() as $e) {
        if ($e['id'] === $id) return $e;
    }
    return null;
}

}
