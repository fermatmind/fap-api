# English page consistency findings — 2026-10-19

## Coverage and exclusions

The audit checked all 1,046 manifest-bound English Career Current assets,
105 current English canonical URLs outside personality details, career details
and test-taking routes, and the 72 published English articles returned by the
public article feed. Feed membership does not establish indexability.

Personality detail content and Chinese content were excluded from the changes.
The resulting content changes cover 71 distinct English career pages and three
CMS pages: the homepage, career test category and AI-coach article.

## Shared findings

- Forty-one career SEO titles supplied their own trailing FermatMind brand.
  Career metadata adds the brand again. One suffix used a full-width separator;
  checking ASCII separators alone missed it.
- Seventeen career pages had 47 hero metric references that could use an
  existing, same-scope 2025 fact on the same page. Other selected hero labels
  named a newer period than their bound historical facts supported.
- Four career pages required changes to the underlying figures and their
  dependent prose, FAQs or source cards, rather than a label-only adjustment.
- The career test category retained older questionnaire lengths, durations and
  IQ explanations. The homepage and its header/footer block copies also
  retained an older IQ explanation.
- The AI-coach article promised 16 personality types in its title but described
  eight use patterns. Its title and SEO/OG titles now match those eight patterns;
  the body, description and URL are retained.

## Projection facts and statistical scope

The source is [BLS occupational projections, Table 1.2](https://www.bls.gov/emp/tables/occupational-projections-and-characteristics.htm),
2025–2035. Employment and opening counts in that table are expressed in thousands.
Annual average openings include replacement needs; they are not net job growth.
Figures below are converted to individual jobs and annual openings.

| Career page | Statistical occupation | Employment, 2025 → 2035 | Growth | Annual average openings |
| --- | --- | --- | --- | --- |
| Astronomers | Astronomers, 19-2011 | 2,400 → 2,600 | 7.8% | 100 |
| Acupuncturists | Acupuncturists, 29-1291 | 14,800 → 16,000 | 8.6% | 800 |
| Media Programming Directors | Producers and Directors, 27-2012 | 181,000 → 188,300 | 4.0% | 12,100 |
| Flight Attendants | Flight attendants, 53-2031 | 133,700 → 145,500 | 8.8% | 18,500 |

The media-programming page uses its parent occupation, not a separate estimate
for that specific job. Percentage changes should retain the published value;
rounded employment endpoints do not reproduce every published percentage.

## Reader distinctions and retained content

Homepage CMS quick-start descriptions feed JSON-LD, while visible test cards
use existing frontend copy. Header/footer page blocks are another copy consumer.
Article and career metadata have different brand handling, so a brand suffix in
an article's stored SEO title alone does not prove a duplicate rendered title.

An older projection period is not itself an error. A historical-period string
in raw block JSON can also come from an internal reference ID, even when the
referenced fact has a newer period. Such matches do not establish stale visible
prose. Explicitly scoped historical material was retained rather than relabelled
as fresh data. The other 71 public
articles had no equivalent title/body count mismatch in this focused review;
that is not a review of every scientific claim or a guarantee of search ranking.
