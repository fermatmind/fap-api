# 费马 MBTI → O*NET 投影：文献核查与 16 × 6 矩阵草案

2026-11-11 · Research draft 0.1 · 仅供离线检验。本轮冻结文献口径与候选矩阵；尚未运行职业 Top 10/20，也未变更现网推荐、索引闸门或页面。

## 结论

可以构建一个有公开来源、可复算的**研究投影**。目前最直接可复算的输入，是同一份技术简报中五组连续 MBTI 分数与六维 Strong 兴趣分数的相关系数。它能回答「如果把四个偏好方向合成一条假设轮廓，结果长什么样」。它还不能回答「某个 INTJ 的兴趣得分是什么」「工业设计师更适合哪几型」或「推荐是否改善真实职业决策」。

最值得警惕的是：**16 条不同的向量，不等于 16 份不同或有效的职业榜。** 本轮计算中，ISTJ 与 ISTP 的投影形状 Pearson 已达 **0.948**。生产开闸不能用矩阵生成成功、榜单不同或 Schema 校验通过代替有效性证据。

## 1. 文献和竞品审计

| 原始资料 | 实际测量或公开做法 | 本轮用途 |
| --- | --- | --- |
| [Herk & Thompson (2011), International Technical Brief for the Strong Interest Inventory Assessment](https://ap.themyersbriggs.com/content/Research%20and%20White%20Papers/Strong/Strong_Intl_Tech_Brief.pdf#page=77) | 五个语言版本样本共有 491 名配对受测者，使用连续 MBTI IRT theta 与 Strong 六个 General Occupational Themes（GOT）。A.5–E.5 各有 6×4 相关系数。 | 唯一用于生成本稿权重的数据。公开 PDF 的总表 11（印刷页 10、PDF 第 12 页）在前四行出现对角空项且系数方向与正文叙述不一致，因此跳过总表。 |
| [Carless (1999), Career Assessment](https://doi.org/10.1177/106907279900700203)，[作者论文公开副本](https://citeseerx.ist.psu.edu/document?doi=f677cba2659027ef2be8a8ba70134016088f1626&repid=rep1&type=pdf) | 研究 2：澳大利亚银行职员 875 人，MBTI Form G 连续分数与澳版 SDS。正文报告女性/男性样本中，N–艺术型约 .36/.35，E–社会型绝对值约 .41/.34，E–企业型约 .44/.38；N–研究型则约 .15/.30。 | 前三个关系可作方向上的交叉核对。量表、采样和分析分组不同，不把选读的系数并入权重。 |
| [Nordvik (1996), Relationships between Holland's vocational typology, Schein's career anchors and Myers–Briggs' types](https://bpspsychub.onlinelibrary.wiley.com/doi/abs/10.1111/j.2044-8325.1996.tb00614.x) | 1,063 名挪威成人；Holland 与 Schein 使用个体内相对取舍的量表（ipsative）。 | 只核实研究设计和摘要；不能与 Strong 相关系数直接合并。 |
| [Furnham (1996), The Big Five versus the Big Four](https://doi.org/10.1016/0191-8869(96)00033-5) | MBTI 对 NEO-PI 大五人格，研究对象并非 Holland 六维。 | 不纳入；也不把 MBTI–大五和大五–RIASEC 两张相关表相乘当作实测。 |
| Hammer & Macdaid (1992), *Career report manual*；[引文书目](https://doi.org/10.1177/1069072708318902)；[Myers-Briggs Company 2020 职业报告技术简报](https://asia.themyersbriggs.com/wp-content/uploads/Technical-Brief-for-Updated-MBTI-Career-Report.pdf) | 1992 手册仅核书目，未取得全文。2020 简报公开了不同做法：美国商业档案 1,327,247 名满足筛选条件的在职受测者，覆盖 746 种职业；以职业中某型的比例除以全样本中该型的比例排序，O*NET 提供职业分类和档案。 | 这是选样后的职业分布数据。可作为独立方法参考；不能当作 4×6 相关表、因果证据或个人职业预测。 |
| [Pulver & Kelly (2008), Incremental Validity of the Myers-Briggs Type Indicator in Predicting Academic Major Selection](https://doi.org/10.1177/1069072708318902) | 该大学专业选择样本中，Strong 单独正确分类 45.4%；加入 MBTI 后 48.3%，增量未达到统计显著。 | 支持下一阶段把实测兴趣单独列为基线。专业选择研究不能替代职业结局验证。 |
| [Truity：TypeFinder for Career Planning](https://www.truity.com/test/type-finder-careers)、[按类型浏览职业](https://www.truity.com/search-careers/personality-type) | 测评公开说明同时测人格与兴趣；另提供只按类型浏览的入口。公开页面未披露权重、职业映射来源或缺测兴趣时的回退规则。 | 可借鉴「测评」与「浏览」分开，不能推断浏览入口使用同一套引擎。 |
| [Zippia：INTJ 职业指南](https://www.zippia.com/advice/best-careers-for-intj-personality/)、[INFP 职业指南](https://www.zippia.com/advice/best-careers-for-infp-personality/) | 可见署名职业列表、职业理由、薪资及岗位入口。职业数据可以支持岗位事实，页面未披露类型映射系数或降级算法。 | 可借鉴任务解释与行动入口，不以编辑名单校准科学权重。 |

## 2. 矩阵构建

**原始输入定位。** A.5 European English 94、B.5 French 104、C.5 German 128、D.5 Latin American Spanish 61、E.5 European Spanish 104。印刷页分别为 75、93、111、129、147（PDF 第 77、95、113、131、149 页）。逐表取 Realistic / Investigative / Artistic / Social / Enterprising / Conventional 六行的四项相关。附表分数正端为 I、N、F、P，负端为 E、S、T、J。它们是连续分数相关，**不是四字母类别的六维均值**。

按同一简报分组做 Fisher z 描述性加权；不把它当作原始个体数据直接汇总的 Pearson，也不把五组当成五篇独立研究的验证：

$$
C_{j,d}=\tanh\left(\frac{\sum_g(n_g-3)\operatorname{atanh}(r_{g,d,j})}{476}\right),\qquad W=XC.
$$

其中行 $j$ 是 MBTI 连续轴，列 $d$ 是 Strong 兴趣主题。$X_{16\times4}$ 从四个字母生成符号行：I、N、F、P 为 $+1$，E、S、T、J 为 $-1$，例如 INTJ 为 $[+1,+1,-1,-1]$。先合并原表系数，再计算矩阵，最后只对展示值四舍五入。

| 矩阵 C 的正端 | R | I | A | S | E | C |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| I | -0.068929 | -0.011728 | -0.106519 | -0.162075 | -0.252214 | -0.053802 |
| N | 0.023970 | 0.110858 | 0.374077 | 0.068973 | 0.094405 | -0.072749 |
| F | -0.136044 | -0.086736 | 0.180535 | 0.196487 | 0.007325 | -0.018338 |
| P | 0.092789 | 0.034689 | 0.164656 | 0.054831 | 0.080265 | -0.031925 |

**$W_{16\times6}$ 草案。** 这里的数是无单位的、有符号的方向指数；负值不代表讨厌某种活动。加总可以超出单个相关系数的范围。每轴使用等幅符号是工程假设，不代表四种偏好的强度相等；不含类型交互，也未用费马配对样本拟合。只掌握边际相关，无法求得校正轴间关系的回归系数或校准 $E[\mathrm{RIASEC}\mid\mathrm{type}]$。

| 类型 | R 现实 | I 研究 | A 艺术 | S 社会 | E 企业 | C 常规 |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| INTJ | -0.002 | 0.151 | -0.078 | -0.344 | -0.245 | -0.076 |
| INTP | 0.184 | 0.221 | 0.252 | -0.235 | -0.085 | -0.140 |
| INFJ | -0.274 | -0.022 | 0.283 | 0.049 | -0.231 | -0.113 |
| INFP | -0.088 | 0.047 | 0.613 | 0.158 | -0.070 | -0.177 |
| ISTJ | -0.050 | -0.071 | -0.826 | -0.482 | -0.434 | 0.069 |
| ISTP | 0.136 | -0.001 | -0.496 | -0.373 | -0.274 | 0.005 |
| ISFJ | -0.322 | -0.244 | -0.465 | -0.089 | -0.420 | 0.033 |
| ISFP | -0.136 | -0.175 | -0.135 | 0.020 | -0.259 | -0.031 |
| ENTJ | 0.136 | 0.175 | 0.135 | -0.020 | 0.259 | 0.031 |
| ENTP | 0.322 | 0.244 | 0.465 | 0.089 | 0.420 | -0.033 |
| ENFJ | -0.136 | 0.001 | 0.496 | 0.373 | 0.274 | -0.005 |
| ENFP | 0.050 | 0.071 | 0.826 | 0.482 | 0.434 | -0.069 |
| ESTJ | 0.088 | -0.047 | -0.613 | -0.158 | 0.070 | 0.177 |
| ESTP | 0.274 | 0.022 | -0.283 | -0.049 | 0.231 | 0.113 |
| ESFJ | -0.184 | -0.221 | -0.252 | 0.235 | 0.085 | 0.140 |
| ESFP | 0.002 | -0.151 | 0.078 | 0.344 | 0.245 | 0.076 |

**本轮实测。** 从 PDF 文本读取五张表的 120 个系数并核对每组样本数。24 个位置中 14 个在不同组之间正负换向（零不计）；N–艺术型在五组同方向（.30–.44），合并约 .374。生成的 16 行各不相同，八组互补类型逐维符号相反；最高的异型形状相关是 ISTJ–ISTP 的 .948（120 对）。INTJ 用各单组重新生成，与合并版的形状相关为 .958、.829、.956、.299、.963；逐次去掉一组再合并则为 .994、.990、.995、.998、.994。前者暴露组间差异，后者说明没有单组独自决定合并版方向；两者都不能视为外部有效性验证。

最小复算逻辑（输入为原表数值，顺序按上文）：

```python
from itertools import product
from math import atanh, tanh

# r[g][d][j]: five tables A.5–E.5; d = R,I,A,S,E,C; j = E–I,S–N,T–F,J–P
def project(r):
    n = (94, 104, 128, 61, 104)
    c = [
        [tanh(sum((ng - 3) * atanh(r[g][d][j]) for g, ng in enumerate(n)) / 476)
         for d in range(6)]
        for j in range(4)
    ]
    return {
        ''.join(t): [sum((1 if letter in 'INFP' else -1) * c[j][d]
                         for j, letter in enumerate(t)) for d in range(6)]
        for t in product('IE', 'NS', 'TF', 'JP')
    }
```

## 3. 下一阶段的离线验收条件

1. **分清两个任务。** [O*NET Interest Profiler Manual 第 7 章](https://www.onetcenter.org/dl_files/IP_Manual.pdf)用 Pearson 比较实测个人兴趣与职业轮廓。将草案投影代入同一公式，只是借用数学方法，不能沿用 O*NET 的匹配等级或称其官方 MBTI 算法。Pearson 是分别去均值后再归一化的点积；等值向量无定义，近乎平坦的轮廓不能靠归一化获得可靠的排序。也不把系数当概率。
2. **固定输入与缺失规则。** 对 1,046 个职业身份做覆盖盘点；只对已确认且范围一致、带固定 O*NET 数据版本和完整六分数的职业评分。不得从三字码补造六分数。共享一个 O*NET 代码的独立职业仍保留独立身份，在检测榜单重复时同时按 slug 和源代码计算。待核版本、缺值、组合页及范围冲突各自记录，不把未评分项计作已匹配。
3. **测试榜单和真实效度。** 报告 120 对类型的 Top 10/20 完全重合、交集和顺序重合，并做删组和近似并列敏感性。不要通过强制去重或换排序来制造差异。再用独立、知情同意的配对样本，检验费马类型/连续偏好对实测兴趣的预测，并与简单均值、直接测兴趣等基线比较；校准与评估分离，检查语言与分组迁移。缺乏有效性证据时维持 MBTI 闸门，用可核查的工作任务示例与实测霍兰德入口承接。
4. **公开表达。** 若后续发布方法页，写清量表、样本、版本、分数语义、排除与复现方式；公开页面和 JSON-LD 只描述实际证据，不宣称职业与性格的因果、资质或强契合。结构化数据不能保证搜索收录或 AI 引用。
