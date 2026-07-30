<?php

declare(strict_types=1);

namespace App\Services\BigFive\AuthorityV3\ReadOnly;

use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Services\BigFive\AuthorityV3\Release\BigFiveEn52PackageCompiler;
use App\Services\BigFive\AuthorityV3\Release\BigFiveEn52Publisher;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2SelectorAssetContract;
use App\Services\SEO\BigFiveCanonicalRouteCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class BigFiveEnglishDraftInventory
{
    public const SCHEMA_VERSION = 'en-parity-w2-big-five-runtime-draft-inventory.v1';

    private const HISTORICAL_AUTHORITY_PACKAGE_SHA256 = 'fb67edc033e679da3f134b34db30901465c7b44e0585818b23613fab83bf9162';

    /** @var array<string,array{0:string,1:string}> authority key => [source hash, stable snapshot hash] */
    private const EN52_DESCRIPTOR_LOCKS = [
        'big-five-hub' => ['1826e1756ee58c258ace2bc302430766fd179a303b3999c99da64a32d28be99f', '9e6bae88ab22dcbac03cac7ab3b4f572991d91f081da0dd701b182b481e00607'],
        'agreeableness' => ['b3f33c0a35597d35bdcb0f2436a2990cf0fca92958bcd54a04a9f35c81eb1d94', '43704f8684f3a620fcb67152b9a6133cd564978c31c7c60a877e569bda3e61bb'],
        'agreeableness-high' => ['adefd0ba11dd924f5d3d76e2a4d7e7ec3ee111fa1cea48b9858010b1ba1a9e07', '474c80b1e4b486482e6135ccbab50ca81b13a7a9c6b40109db15bad659288245'],
        'agreeableness-low' => ['880e90540b992034d758517f88c8f870d0dcb093ca98927fc048f71ce07ba463', 'a5fab6ad84c11f3ff1787401909c5025ff736a55c96604b66c2ddcdee436cb23'],
        'agreeableness-mid' => ['ae58800966a33ccf30ee6720cdd7b46d74c02d0a355c7457e640c046bd7c63c3', 'f4ae7fcddac67b1439de74ddc102b1ba0db7e836f70e0a22cd7d2c9c9a44151a'],
        'conscientiousness' => ['c679485740b5bf9e696b04cffb1df7345bd71bbc7cbf7c621ad84fe7b8b24de2', '0513ae2dbf67d1f4af741b96a07c093d6f6ae2793f69b056705f9aa5b1e0d5f5'],
        'conscientiousness-high' => ['7729597b59b2608fbc410363afe3f8453ac66cec0fc77022a981c9c66790d072', '28db7228d7b784a7c76f1e16d73e14bb84fe8618347a35bbc7db73aaafc761c0'],
        'conscientiousness-low' => ['200e4f28f980bd4cb11ddc897c2b52338380ca772ecbabd205f61d7655f468fa', '5b4ef468ce3a356972a6b9318cce88807930c3fdcd12d09469d972fa7ab4a927'],
        'conscientiousness-mid' => ['32c4d1aa8a54a2958e47b5c71bb2bf30197cf5c7a2e0acdaa1ea0268906d57e9', 'ee59fe2d95bb6f3d7bd884011c60dcf230097bbc2e2dd46a42e1e593cb76587a'],
        'extraversion' => ['aa99679cb79fca912d2e222b8c61d0c94db74552e1ab30477b761997d8fb75db', '1a541ccf2c038f82e1ac6506e36dc56d3a3debad3c0855359c66c61df69334c5'],
        'extraversion-high' => ['16a0e884f8c118514d835f7471025a1428fbf8bbbca97dd9a0633c8e9163bf83', 'a50f2310babdf0255fd4b987a0e40bbb1687684ad3a19f6515d925ed7703aa9f'],
        'extraversion-low' => ['76a5e2efe10756501778e63cd130ace6323680ff87c8ffde4a408513431e51c7', '9486413e4ad4042b7bdb23f01f1071a378a9b9328ba6707d1539fb5dbe9c08bf'],
        'extraversion-mid' => ['12446806e62358c9a8cb85aa708c4bc55eda93aa16e77013e0d939863493e5a5', 'ee37026517b009406049334592647901eb8adf0bd2d46138ef3168742a05554e'],
        'facets' => ['89562341b6c35ea99814cf5d04158c68659ec4b160bb6d0a1e04ceabc5750655', '8cb08df536115ed29262707f640c41afd37cc6348beb8d31f27ac92984bbea23'],
        'C4-achievement-striving' => ['c21a6dab6b0027bfc3f217dcf245da3e049049ec2493e0718b4a298a39a2f1c2', '35d48975de22d2afe5770a1544cf2f2b3cf523baefbd04654febc26dac900684'],
        'O4-actions' => ['632204b53cf29b4692cef45df39fc76a7720520f7572bc7c57da02c9a1443643', 'e3701d36f2d11218ce6882965b53e74e718806e8745cd7f0b38bf1f8e103aac8'],
        'E4-activity' => ['e59f2063618d430c0acac645816f16c228de6b78771151f59446a1df1b1f25ee', '454aaddc7c48658d48a54f7459c3ad0a285df411638667e1a1fb11f46e55cb7d'],
        'O2-aesthetics' => ['eab6610b40b543040fb12a011240b84082c31595353e42572c436482c34a8c24', '78ae75154de9dcb146e908ae05dde95f06234b192c128584a7984af1a2664eda'],
        'A3-altruism' => ['f86f2f8be0fc896fd516239b53304a1056d3563d66f94fd5ceac5ffc03e47f58', 'e93c832f30df54baf7c9fc896a0352e574d8db9856795ec54ad57989dff3afe8'],
        'N2-anger' => ['451aaa49b7c688ca07bbef781fae38772e0f3af22e386ce64560d1a92257fde1', '98b43742104349b198aa705bcacf7edd4eeeb0d90687ad7a638f1248cbe122fc'],
        'N1-anxiety' => ['7c45b0dca5b0b6d4caa1a64d0454588bd2153ebc7ef007b44066f56a4fcb1728', '8a150fd4fbade8368c5b22f68b0bee5288c30cad6c3bf44f8179f6ea0847775e'],
        'E3-assertiveness' => ['80f7bad5c2c527499c1ec81f4bed229622633e2b37327e969176bebd52e902a0', 'adabfb6fcca9f4ee547a585b1a0a1b0ece92355a9125417f5c20e8230efd1287'],
        'C1-competence' => ['2d8052e7d67773d2e4e19e07e4f15585d8f9570d85b93846d10a4f73fdd6d4e4', 'e85e6d251faa50be33fd77de545fb620294427d586d305c44750722789feb53a'],
        'A4-compliance' => ['496589024390a17aff8f0a6376b26f5ec7b56223a8f723da7d73160af1d0e146', 'c96477162808b9d314526f0b993f2cdc80ca96b3b1e64f1ac5cefccedf5dfbdf'],
        'C6-deliberation' => ['a2105d5791a0073ee23492b0681ebe08ce0a68f2cc5a920b217ee2d9a5072da2', '2584f914b2ceff0cf179740ec2b64796bedef21d1427a542b966b21a98af2a09'],
        'N3-depression' => ['15661554a091135f03c2e541718397a001b7f7301bfe5340e6bcfd5c8007a20e', '3b39856e4a4276048ff61af4f241f877651bbd1f0d8030bce62d49dd1c6d1fbf'],
        'C3-dutifulness' => ['d717e8a54366b2c554242d6e2554d584b8a19a7cc7723ca93221a14e187ed32f', '9e92cd60a7c719072c654769b2690eb54b211d5becbcf4e9dc03d8bac21ac498'],
        'E5-excitement-seeking' => ['b734ad32bd2fa23541837a9ec66eae2cf3ce248bf109ff8d4ae026997779101e', '76b0ed136fc9437159eb322677e22438ece88f29422a42c71039a621e96194a2'],
        'O3-feelings' => ['cf5b4ffcb05c6f33b538cddfa3f81ebc3f10e03da64c7c71467e5c54155241ff', '800f29572f09b98337332824366a60a126c379f7b01d91ee411c9f2291ddd7c8'],
        'E2-gregariousness' => ['434925e450b1096ed55f2c15c87e339c980bbdc2004f82b641003b9d6782a470', 'c1b642e3c592962d9cd1513dfbd44ccd35ad032b0251126f4b571b4d0314bfca'],
        'O5-ideas' => ['cde3e3bfd7d1ea45691a01b04ae71b25b64651de5f53021dc1f0990296c91106', 'a50aec7fe17005b07c317a6c51736b918b1d5d4b2a475f4b6c03aa09c6a063ca'],
        'O1-imagination' => ['98d299366cb244a344ea9f9727b79ee70e5936a738a907605f0a2879eb0a0407', '984c4238725a0b515d79283ca3c349c549f510585f31634dc90da931668e54f0'],
        'N5-impulsiveness' => ['920264f3a6bafc05f9d6bb41d4c933bfd9742426c16be3a3adc073ef6217ac47', '8aa18afd88d26f2612ab1ebfb1794cd218696abbf9abedb7c4ffab7a71e384ea'],
        'A5-modesty' => ['fed11ef849304e530237016c5c8c1253291559edd7ff0bee6da10d54d0f2bedc', '36d43a3fb59b6357080b9f70e8642f2fdd791aa23f1389f5a0f41238289c9eef'],
        'C2-order' => ['dc726370c0b781e40b16e4bb90c8ec37d38e3994966698be5557d8b070a272d3', '006ad0beb228d8dbce8911b72e9abe1309a7786908fe267d40b211f204f3b770'],
        'E6-positive-emotions' => ['92a99c8493d125ceddf0c9b0a64bb344ab545c83ef1dfd7897b7c0723df975df', 'c746a3a67fc898691d0feae951ae1544b07e5d9e4c097f6aae2c9f19aabda5f5'],
        'N4-self-consciousness' => ['234401a691e5d55caa80ef68039e5933e51c818f903dbd8426d0a387126440c3', '8b4e13c9c5aa383d372447b1e1b328a5e327241a004b3fffa00fc54f68082818'],
        'C5-self-discipline' => ['0f8521dcc10c6b805bb3434df3d2c332f02705809438ab61cd8279ee7738f50a', '52eaef30354193dd640b07cc63596df43f410d8c9bc8847cedf60b72c1cf2b7d'],
        'A2-straightforwardness' => ['f63a2a3796ac67817012d56deba0c14bcce968107f1417067ea28cabaeefa68e', '24ce3071887914f2331fba72046a190f1e7294691bb6ec55bd4d24603e2de523'],
        'A6-tender-mindedness' => ['90710979247d384c54b36c982915aa3e5f1d058d67a89fb656e014ca3d8a9c11', 'ac2e2279c3f1978c121f8dceff373df1967c85768fc67198db0e5e22996720a2'],
        'A1-trust' => ['7dc79bac88139d3e94549f2bf35569ff3a3abe9b133e3378a2a7f64e43b5ffd7', '43a408e906d104e586ddf1ba693ab2a22926081d22e5af92a34cf64b823c2cf5'],
        'O6-values' => ['2999b41026a9a9715f140e57966ef7de9a56b7ec3f9dff085fd76ebb8f7a4607', '157c8af9d03a8e61cfa07ab8d00acb115ed01cffd1d1f5c1ba33f3ac4af7a8ad'],
        'N6-vulnerability' => ['8704d61f3eb0fe37c6cf721bde3b21541897e40fff1112d1500197c42320aa07', 'bd9fe3047323e0df8519f2d46f2af708239bc36ac5ca5da9f3118d24708df550'],
        'E1-warmth' => ['b2751e059222b7573277ee1b03fdb9d288c8e257bc098c8a5ff7e64f9f1dcd9d', 'f79e7aae9d25c9b73d5e5612b34d2154827ee70d14bd1fc01544c13015d7daa2'],
        'neuroticism' => ['ba291559ad4611286ef1b5b6fc4bb7230d49409e438e4fe8e6148d56ac2f8b91', '4590d4c7e8abe63ed7ad8c47ad8ef6fe78808fc7d06c532927e2982241aa15e7'],
        'neuroticism-high' => ['dc54672c0828ce9ee78df90c134b5b8ce8ffef8fa57d6906537d64cf06296e83', '8057fd75db6fb48700804f64cccca7424527c5f74270a90135efa6ca5222fa74'],
        'neuroticism-low' => ['763f7de63f64a9f0070785baadae44e237d2198455f4b7a9dc7ddc0f9e79424a', '6183b26d93652ba4e0a902316fce0bf8bc4d53372cec5dbfa04ab461786cdc1b'],
        'neuroticism-mid' => ['ba853d9f5ed2a4b9242e2f79e0b705edbeb2a105f4d5b7732ca95fd570e86abc', 'fe7871dc01572980f211fa7a1d7872e4c470306955fffcef26a809ab6b4fba3a'],
        'openness' => ['dcf7a96dba43ec835ffcd1104c4e0cf441d354b4f3619d2fb52e9b4ad65316fd', '35d43a4fab7cf36014bb5fbe14283c3d14eedd1cbfa5283c0304620ba0084c03'],
        'openness-high' => ['0cbf6d31580dba3fb6102eb5bbe621d261905458d392ff0efcdd94803d73a05e', 'c24ea2240f7215dd5dda5b0623715bd2d69147d9433403375b40b75cc07189b7'],
        'openness-low' => ['0fd8bcd054962e6928eee03a8b9a17f37527e47b6ba34e2b068272f35866ef85', 'bcf0f2fbe0c79178b6cd16d9289265bd42ae69423a3def04922d0d82cca4981d'],
        'openness-mid' => ['4b07275547b051c773677164ded8fedaf86d2dc20f3db0211c81235ad0ca3e0e', '0b334732f67283dfcaa14c51ff4fcfde75bce45e98d07c2f94cf5dda59d2600f'],
    ];

    /** @var array<string,string> logical identity => Authority V2 draft-import source hash */
    private const HISTORICAL_SOURCE_HASHES = [
        'domain:agreeableness' => '4f9aea648dec2a9d6358f1b3b2f612739748905d669a013a35129c659622c2cc',
        'domain:conscientiousness' => 'fa5b8928b5cf741f8deac6dc5dd7d2b47e2f86a188f93511636439c6b4de3318',
        'domain:extraversion' => '268ffe6fa69958eed4e4b2a49f424e15e9b8e9d9cd106c74af585399b26281c9',
        'domain:neuroticism' => '320a2bc7545f9be804c6b9a4a11f5751a8e1b6686fa0f4da161ff0687b1fcfd6',
        'domain:openness' => 'ce30596ef26b1c630607b4a24cd3e953e6128406932573fd475e3df350b2d9f1',
        'polarity:agreeableness-high' => '18637c3998755b9abfa2a1c2a421c6baac1ac8da6254cf93c6cbc7852e4d92e4',
        'polarity:agreeableness-mid' => 'd5c1fe1f84c0443ddf3b02409af1649a2a873a42c0bc2ae48e56eb84309a9da9',
        'polarity:agreeableness-low' => '0872e4e726f546d5d9f780ab6dbf5fbe1b50815446bbaa3f588ffa1c9b485405',
        'polarity:conscientiousness-high' => '27fee5af134938de14645bcf4dec939b5a3e000d2283879ff1288a4b247a4e17',
        'polarity:conscientiousness-mid' => 'ac0840d0c4c15bbaa98840e8e840c2d41c730d1b08866a21c092db20162d6556',
        'polarity:conscientiousness-low' => 'c871ae34b5679c9b83b4d66b578ac68a555552df2c09ea0d9f7106a7ad22c8ec',
        'polarity:extraversion-high' => '168160efcb51baa07ecfb271cd941e3884db6fb125edb2b630cb406bef209a45',
        'polarity:extraversion-mid' => 'd35a179632d48bc20fa046ff3542e3ac1a3521c37e3e8ac6756ec62f44e0e2c1',
        'polarity:extraversion-low' => 'ff41a6a7d6ba887fe3e122d992b52af966ed0e5dad6d9f1cc37a31a652a0875f',
        'polarity:neuroticism-high' => '1baf8d83a79401705998db475cd27cd620295bcf5e032195bc22319f0fd7a214',
        'polarity:neuroticism-mid' => 'fad70cc35ad7ab47b9c82bdcfa5e8efd10c13812f79a04453d477b3e36f5305b',
        'polarity:neuroticism-low' => '9d275291730345d1deca8167d9382074cd3a289f7eb5e642bd14ff96765401a1',
        'polarity:openness-high' => '4cdb825b7b795a390bd97dcc9af70c15b92d29eaddb728f6b99c1c719d911250',
        'polarity:openness-mid' => '2bca4693623e60bb96cd3ddfa01e5a5651eeddb29eb4944006ab7dde228e52ec',
        'polarity:openness-low' => '577eddd9bad072227780a257142e3fbcce0012011cbd34be3f78f4f4f2296bb6',
        'facet_detail:achievement-striving' => '025893f9938af3f0bfc9ffa36d6f804e7a06798819eb7f4ff9e9b8e16dd7953b',
        'facet_detail:actions' => 'cb8b646d2133fc3f172950bf35bde218896427f318bfeb6b8fb3e283fa08426c',
        'facet_detail:activity' => 'b50ee690b9e8f6ac4734fd3124467c9e5be6e24f22cb431a294a3c37ac59701b',
        'facet_detail:aesthetics' => '0ea47fd15e173e1bb8c4f460418913cb2b2bfacc3fc5f1aa163654dc4d1f33ed',
        'facet_detail:altruism' => 'fea601f91763eabc899814581d80a407faa629fe1b39e5bf3d8fac57c66e87d4',
        'facet_detail:anger' => 'a006c5e27cbbb309fb6048c8ccc318bcb1354dbd653cb457985c54278c4cd8db',
        'facet_detail:anxiety' => '64563cca90a165487e2c1e5bcdf431a215b00794daa89687ce3e5a92cfa4ada9',
        'facet_detail:assertiveness' => '5b78cceede44ec61008b92f7549b405e5883d066b7789690606e73a3cf2a84b4',
        'facet_detail:competence' => '0d0074b0e8f28f3b66c3b1caeafa29dba971aa5ef92c34053088c740de37efff',
        'facet_detail:compliance' => '7302c1a10f2ac51427b127d51ec1c882da5113f5efd7a02500732136fd1eb560',
        'facet_detail:deliberation' => '17f2a62017ff755e59318d2337ea6151f189e139352f05b5504ab6619c41553b',
        'facet_detail:depression' => 'e657a0bd575b29dbe01dc241e0424571b98d8b8a640a3e10debf9da442b73529',
        'facet_detail:dutifulness' => '4cbf45ab5988079d699fae45ca8078d4f2a44e1a2eb2a5505b55be0fe8a7070e',
        'facet_detail:excitement-seeking' => 'a6844f5d1edcd71f0d7e8081e7e0ea026de9f4a1aa9076f52efa835fbd4646f3',
        'facet_detail:feelings' => '679ac2f76ca4b1fb7fb8b1dc39c1e2fe9c1d9a227da45ee84c467aa17e8702cd',
        'facet_detail:gregariousness' => 'cdae353f4f95be6429137ebb7c715f9a2eb37ba126dbed8371ec5b2240524146',
        'facet_detail:ideas' => 'f0f87feb863651be073b6a6edf0572d8f8b66fd48203d0d667cea9a7852ab432',
        'facet_detail:imagination' => '13c2d7135423a730f1a929f14f53b6e6e5443fbf154ec13d5aae11f0cc7f7365',
        'facet_detail:impulsiveness' => 'de1db23c519842d57039fc4fc95a112f99da9005ebd05ad92808bb7ef93687d5',
        'facet_detail:modesty' => 'c16dbf07e3e11e001d7af1be455fe763decd4d8b4867118167c80dc99da40521',
        'facet_detail:order' => '98b0ecbbce9f33ec9df7ed9ec2c642041398761d43a0249ca1194f02330d90ad',
        'facet_detail:positive-emotions' => 'fb6b73e74de9ebfe9eb90bc1af23d28e108aa2251955bec97673b883bb9cf697',
        'facet_detail:self-consciousness' => 'e05403c6544b723dc8defec4d1cb5d1ac1f1e43e9cda3870813d649b334a7a88',
        'facet_detail:self-discipline' => '85f00c3c44503270041b5a8f6875ed64e0c86b47102f29de16bb9e231925e8a4',
        'facet_detail:straightforwardness' => '31b6c0f587226570db3e3a0e8128ae2725c82752a9de3dbe73ba7cc44209901c',
        'facet_detail:tender-mindedness' => 'f243478c998e5b0a2183b84a2cd20cf5b1ca27f8784135bf76fd02c14346d9b8',
        'facet_detail:trust' => 'b35f5d86d720c755c3196ad085026320407fc4552762b7c76c1cd0bb395c117d',
        'facet_detail:values' => '7f59f69d08d615e57439fefc5194f1b0dc165f69b02f79898419627007a5a52c',
        'facet_detail:vulnerability' => 'b46fcd41cbebbfbcefde01157e94cc887ec2ebbd28a96e15272c157c87627935',
        'facet_detail:warmth' => '37023425ec12320e7f1a3779917f032de2d1e1dc8ec026af568be2e702fff3e7',
    ];

    /** @var array<string,string> logical identity => stable transformed Authority V2 snapshot hash */
    private const HISTORICAL_SNAPSHOT_HASHES = [
        'domain:agreeableness' => 'fb6dee35f2a1f1ffac016ee9d4c23a8acdc45bb439ca81fb3b9b101e68f94e88',
        'domain:conscientiousness' => '7283ec87997fcf08aedcd3818c54a64fd697eda2ac1ebacbec9785d404e95e1a',
        'domain:extraversion' => 'a798b2255d98392a601cfcad3bea0a80eaa6ec5f8d2dcd1d0f3ff2fb3bf3bb62',
        'domain:neuroticism' => '8e0d8d2e0655d522e515fb23b874b9660ff99c2ea02970fcf5121c3bcacd6ce6',
        'domain:openness' => '36c7d6d6d44b27777735ba999f247aa57d91ca2135af0a6a017a6570d6a06edf',
        'facet_detail:achievement-striving' => '669e91038a5610149fa99a60badcdd80407fbb7337af3a0977ff49444dba5a66',
        'facet_detail:actions' => '8fd14800efc8e083b360c62242086eaf6b5cd2b308b2ed51b291482a278e4ee9',
        'facet_detail:activity' => '50626826afb1730910c054879fcd01cc32656e3bdf5739f9352ceff506b07ec7',
        'facet_detail:aesthetics' => 'fc349650cb9b09e36a5464c3867ca06b81da412a1c7f50a94db6f4fdd29329d5',
        'facet_detail:altruism' => '7e4e3e00f6ee4122e1c87410eb4dd50841b480c17ed7e2c27d2986073c00fd0c',
        'facet_detail:anger' => '7beb8e072bcaf8696ac7e28d8d3c42942187a21c1eba5e6a45f890ed90c12754',
        'facet_detail:anxiety' => 'eb5d195a5d7abe6134557953580d235a7b7f3e144038714a977853ad71d8c696',
        'facet_detail:assertiveness' => '38659ec1cf49bff5aea12af1b4289f73a77e28120e274d5aee301475e9edd9c8',
        'facet_detail:competence' => '92c81ad4458d3198cf2c34ec5971305a03643616e4fe0bb33fb26f767f083d70',
        'facet_detail:compliance' => '336d33a680cd6e011896130cfd962d73dc429ee9387fc6c2d49fcec648a62f22',
        'facet_detail:deliberation' => '98a7fa1a15e9a2a68438a95562c6986984f87dccab6d56cd7afd3421ad9a6af5',
        'facet_detail:depression' => 'b4721e1f9eec591ed60c133c8342be4e2e7256dc3034a1042d66f3cccdd354b2',
        'facet_detail:dutifulness' => 'af5ab839f535269cf7c6c2f9dfaffa6b8496d38abafa34503ee354a347d448a5',
        'facet_detail:excitement-seeking' => '437aed58c3f9776275ff896f4da7d865ae27f92500c7bc61d13fc7ee3cffc5f1',
        'facet_detail:feelings' => 'f0ad3e6f2f23856edbfcd057fd9d6549116b968609a06204d99910adee78bdd5',
        'facet_detail:gregariousness' => '4dfbfe3dd3feb6102bfb3b0a026dd0cac341a7d85b5b054f32c173b6615e509e',
        'facet_detail:ideas' => '67a402661920c26753930fb048ea351bbd4d9afa69c3ccb8c51feb3e644d42ca',
        'facet_detail:imagination' => 'a4f518a06cbddead6dc08ad1e2d313660e2fe69f073065380a73ed9086a9a25a',
        'facet_detail:impulsiveness' => '01a3fce05106b1b9dd47e2be06c1ea0ab93e178b4d08ca9660cca53d4148fef7',
        'facet_detail:modesty' => '157d7698e76b116f5b37ef5904471cf0e1d63b5d70f78cb976ff8c4d83e3989b',
        'facet_detail:order' => '8658d32e7d4bd89330d9a41e6e69e1363715db7d927fb463978960514dc9f50c',
        'facet_detail:positive-emotions' => 'c13e808f3ae78ba664e3710bedf12b8a6494f4eca105d34ed074dfb9b2deeb8a',
        'facet_detail:self-consciousness' => '86fbddf00fb023831c35a1ee11b73c37ca57c441e0ca0b33c3d645cde84a3368',
        'facet_detail:self-discipline' => 'b15ba1b054e8131c6db89e952f65874d624c7e070baf853fa8b537d35f88f0d7',
        'facet_detail:straightforwardness' => '4ea504e079abf191faae0c4d0dfa9d5713efc8b25d76b3d11839a3acfb590b73',
        'facet_detail:tender-mindedness' => 'c18c3c7fbd0b60da4f4cb8fa21ee4a632804c761eba9405895a9b9f93822688e',
        'facet_detail:trust' => 'c5d433c413bab3315fdb728e4fc01a4679c279eeba487e5ef758c508f27473dd',
        'facet_detail:values' => '72fb99aaba07bb6191680144af7a77fef97bfa7d525dc05ee47e462fa5de8618',
        'facet_detail:vulnerability' => '0a5283ceba9f837b5f7be2dad00b79b30a83b6b99537bc3ae1cd1ce7d7b39dd8',
        'facet_detail:warmth' => '3aa9e595252879c3092052664bf20ba8c3a0b94e27075de1dd89d280754be199',
        'polarity:agreeableness-high' => 'c2626b31c28dd2ecc5c7d1114b8aae5823ace6bdefa9616e4ad8e188861f5fbb',
        'polarity:agreeableness-low' => 'f1b326abf79b655e70e5f5165141feb80ef7117622cbdc7f11d87c3e36509d27',
        'polarity:agreeableness-mid' => '10fd1518a10a2c4386b31249308a6666a97c1337ba20f379fdf76e8e3a184a9a',
        'polarity:conscientiousness-high' => 'bdda353df7ffd3592bb7916d441a307fdbc1013438caf1208698cac40a435b73',
        'polarity:conscientiousness-low' => '265c9e36c3cbee186e9776e57f3c8e3dbdff3271c2bf294667841539baafa4ab',
        'polarity:conscientiousness-mid' => '8b247035bf9ac2585d7fe7bfab8c3f2ee9471698308b5b3713ddc6879ae1244d',
        'polarity:extraversion-high' => 'e130a787161e157377676773e5cb7e370a9cd037fbe5d4cb4cb09d5291e8df68',
        'polarity:extraversion-low' => 'eec013fa4d4040ea8ce4a298602e4e0919ea00badf9898f10a67d38e4eb36ed1',
        'polarity:extraversion-mid' => '78872e525856ddf69fab8c9f6695b95d5c31200d41f5c9558d201fc58c76bfbd',
        'polarity:neuroticism-high' => '4b89da9fedd6668c9b257cfeb50e9223c3deb118f3d571b28a1dadf4f7b58cc8',
        'polarity:neuroticism-low' => '6dbbba24f0cc65b932075c4b4c9156c939f1ad38eef1d4b5fe4a4082a60b263c',
        'polarity:neuroticism-mid' => '872b086efac0b3d50aafb4547e5c6a8a07e66b7666814115bfa80bf320979077',
        'polarity:openness-high' => '72f2f203459492a4a6a863f2592c6aceae5693a1ff59f723bfdb78150ff5e4b8',
        'polarity:openness-low' => '5052588fe14d7a091ed111ee30b75d7d40c450dc53bfc37642f928e5692b3dc9',
        'polarity:openness-mid' => '2fd2af491e66c25d9b9e3ae4d0751be529950ccf0845b90a77a78d6438256f7c',
    ];

    /** @var array<string,string> */
    private const HISTORICAL_PACKAGE_BY_DOMAIN = [
        'agreeableness' => 'big5-authority-v2-range-agreeableness-13',
        'conscientiousness' => 'big5-authority-v2-range-conscientiousness-11',
        'extraversion' => 'big5-authority-v2-range-extraversion-12',
        'neuroticism' => 'big5-authority-v2-range-neuroticism-14',
        'openness' => 'big5-authority-v2-range-openness-10',
    ];

    /** @var array<string,string> */
    private const FACET_DOMAIN = [
        'achievement-striving' => 'conscientiousness',
        'actions' => 'openness',
        'activity' => 'extraversion',
        'aesthetics' => 'openness',
        'altruism' => 'agreeableness',
        'anger' => 'neuroticism',
        'anxiety' => 'neuroticism',
        'assertiveness' => 'extraversion',
        'competence' => 'conscientiousness',
        'compliance' => 'agreeableness',
        'deliberation' => 'conscientiousness',
        'depression' => 'neuroticism',
        'dutifulness' => 'conscientiousness',
        'excitement-seeking' => 'extraversion',
        'feelings' => 'openness',
        'gregariousness' => 'extraversion',
        'ideas' => 'openness',
        'imagination' => 'openness',
        'impulsiveness' => 'neuroticism',
        'modesty' => 'agreeableness',
        'order' => 'conscientiousness',
        'positive-emotions' => 'extraversion',
        'self-consciousness' => 'neuroticism',
        'self-discipline' => 'conscientiousness',
        'straightforwardness' => 'agreeableness',
        'tender-mindedness' => 'agreeableness',
        'trust' => 'agreeableness',
        'values' => 'openness',
        'vulnerability' => 'neuroticism',
        'warmth' => 'extraversion',
    ];

    /** @var array<string,string> */
    private const FACET_EN52_AUTHORITY_KEY = [
        'achievement-striving' => 'C4-achievement-striving',
        'actions' => 'O4-actions',
        'activity' => 'E4-activity',
        'aesthetics' => 'O2-aesthetics',
        'altruism' => 'A3-altruism',
        'anger' => 'N2-anger',
        'anxiety' => 'N1-anxiety',
        'assertiveness' => 'E3-assertiveness',
        'competence' => 'C1-competence',
        'compliance' => 'A4-compliance',
        'deliberation' => 'C6-deliberation',
        'depression' => 'N3-depression',
        'dutifulness' => 'C3-dutifulness',
        'excitement-seeking' => 'E5-excitement-seeking',
        'feelings' => 'O3-feelings',
        'gregariousness' => 'E2-gregariousness',
        'ideas' => 'O5-ideas',
        'imagination' => 'O1-imagination',
        'impulsiveness' => 'N5-impulsiveness',
        'modesty' => 'A5-modesty',
        'order' => 'C2-order',
        'positive-emotions' => 'E6-positive-emotions',
        'self-consciousness' => 'N4-self-consciousness',
        'self-discipline' => 'C5-self-discipline',
        'straightforwardness' => 'A2-straightforwardness',
        'tender-mindedness' => 'A6-tender-mindedness',
        'trust' => 'A1-trust',
        'values' => 'O6-values',
        'vulnerability' => 'N6-vulnerability',
        'warmth' => 'E1-warmth',
    ];

    /** @var list<string> */
    public const DISPOSITIONS = [
        'verify_only_no_action',
        'duplicate_of_published',
        'stale_working_revision',
        'valid_unpublished_candidate',
        'schema_repair_required',
        'editorial_repair_required',
        'translation_identity_mismatch',
        'orphan_revision',
        'prohibited_content',
        'blocked_authority_unknown',
    ];

    /** @return array<string,mixed> */
    public function inspect(): array
    {
        $before = $this->databaseFingerprint();
        $entries = collect(BigFiveCanonicalRouteCatalog::canonicalEntries('en'));
        $expected = $entries->reject(fn (array $entry): bool => in_array($entry['entity_type'], [
            PersonalityPublicContentAsset::ENTITY_HUB,
            PersonalityPublicContentAsset::ENTITY_FACET_HUB,
        ], true))->values();
        $hubEntries = $entries->filter(fn (array $entry): bool => in_array($entry['entity_type'], [
            PersonalityPublicContentAsset::ENTITY_HUB,
            PersonalityPublicContentAsset::ENTITY_FACET_HUB,
        ], true))->values();

        $authorityAssets = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
            ->whereIn('locale', ['en', 'zh-CN'])
            ->orderBy('id')
            ->get();
        $assets = $authorityAssets->where('locale', 'en')->values();
        $canonical = $assets->filter(fn (PersonalityPublicContentAsset $asset): bool => $entries->contains(
            fn (array $entry): bool => $entry['entity_type'] === $asset->entity_type
                && $entry['entity_key'] === $asset->entity_key
                && $entry['path'] === (string) data_get($asset->canonical_json, 'path'),
        ))->values();
        $redirectOnlyAliases = BigFiveCanonicalRouteCatalog::redirectOnlyAliasTargets('en');
        $aliases = $authorityAssets->filter(function (PersonalityPublicContentAsset $asset) use ($redirectOnlyAliases): bool {
            $keys = [
                (string) $asset->entity_key,
                basename((string) $asset->slug),
                basename((string) data_get($asset->canonical_json, 'path', '')),
                basename((string) data_get($asset->canonical_json, 'redirect_from', '')),
            ];

            return collect($keys)->contains(
                static fn (string $value): bool => isset($redirectOnlyAliases[$value]),
            );
        })->values();
        $unknownAuthorityRows = $assets->reject(fn (PersonalityPublicContentAsset $asset): bool => (
            $canonical->contains('id', $asset->id) || $aliases->contains('id', $asset->id)
        ))->values();

        $rows = $expected->map(function (array $entry) use ($canonical): array {
            /** @var PersonalityPublicContentAsset|null $asset */
            $asset = $canonical->first(fn (PersonalityPublicContentAsset $candidate): bool => (
                $candidate->entity_type === $entry['entity_type']
                && $candidate->entity_key === $entry['entity_key']
            ));

            return $this->row($entry, $asset);
        })->all();
        $hubRows = $hubEntries->map(function (array $entry) use ($canonical): array {
            /** @var PersonalityPublicContentAsset|null $asset */
            $asset = $canonical->first(fn (PersonalityPublicContentAsset $candidate): bool => (
                $candidate->entity_type === $entry['entity_type']
                && $candidate->entity_key === $entry['entity_key']
            ));

            return $this->row($entry, $asset);
        })->all();
        $revisions = PersonalityPublicContentAssetRevision::query()
            ->whereIn('asset_id', $canonical->pluck('id')->all())
            ->orderBy('id')
            ->get();
        $historicalByAsset = $revisions->groupBy('asset_id');
        $rows = array_map(function (array $row) use ($historicalByAsset): array {
            $historical = $row['backend_resource_id'] === null
                ? collect()
                : $historicalByAsset->get((int) $row['backend_resource_id'], collect());
            $registered = $historical
                ->filter(fn (PersonalityPublicContentAssetRevision $revision): bool => (
                    $this->isRegisteredHistoricalSlotRevision($revision, $row)
                ))
                ->sortBy('revision_no')
                ->values();
            if ($registered->count() !== 1) {
                $historicalBlocker = $registered->isEmpty()
                    ? 'registered_historical_slot_revision_missing'
                    : 'registered_historical_slot_revision_ambiguous';
                $mayBlockHistoricalLineage = in_array($row['recommended_disposition'], [
                    'duplicate_of_published',
                    'verify_only_no_action',
                    'stale_working_revision',
                    'valid_unpublished_candidate',
                ], true);

                return array_replace($row, [
                    'historical_draft_revision_id' => null,
                    'historical_draft_revision_status' => null,
                    'historical_draft_fingerprint_sha256' => null,
                    'historical_snapshot_locked' => null,
                    'historical_draft_created_at' => null,
                    'historical_draft_updated_at' => null,
                    'historical_draft_pointer_active' => false,
                    'historical_working_pointer_active' => false,
                    'historical_published_pointer_active' => false,
                    'historical_slot_resolution' => $registered->isEmpty() ? 'missing' : 'ambiguous',
                    'historical_source_package' => null,
                    'historical_source_hash' => null,
                    'historical_authority_package_sha256' => null,
                    'historical_private_result_leakage' => null,
                    'historical_media_reference' => null,
                    'historical_chinese_leakage' => null,
                    'recommended_disposition' => $mayBlockHistoricalLineage
                        ? 'blocked_authority_unknown'
                        : $row['recommended_disposition'],
                    'blocker' => $row['blocker'] ?? $historicalBlocker,
                ]);
            }
            /** @var PersonalityPublicContentAssetRevision $revision */
            $revision = $registered->first();
            $historicalFingerprint = $this->fingerprint($revision->snapshot_json);
            $historicalSnapshotLocked = isset(self::HISTORICAL_SNAPSHOT_HASHES[$row['logical_identity']])
                && hash_equals(
                    self::HISTORICAL_SNAPSHOT_HASHES[$row['logical_identity']],
                    hash('sha256', $this->stableJson($revision->snapshot_json)),
                );
            $historicalPayload = json_encode(
                $revision->snapshot_json ?? [],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            );
            $historicalPrivate = $this->containsProhibitedPrivateField($revision->snapshot_json);
            $historicalMedia = $this->containsMediaReference($revision->snapshot_json);
            $historicalCjk = $this->containsCjk($historicalPayload);
            $historicalProhibited = $historicalPrivate || $historicalMedia || $historicalCjk;
            $historicalWorkingPointerActive = (int) $revision->id === (int) ($row['working_revision_id'] ?? 0);
            $historicalPublishedPointerActive = (int) $revision->id === (int) ($row['published_revision_id'] ?? 0);
            $mayClassifyHistoricalLineage = in_array($row['recommended_disposition'], [
                'duplicate_of_published',
                'verify_only_no_action',
                'stale_working_revision',
            ], true);
            $historicalMayBlock = $mayClassifyHistoricalLineage
                || $row['recommended_disposition'] === 'valid_unpublished_candidate';

            return array_replace($row, [
                'historical_draft_revision_id' => (int) $revision->id,
                'historical_draft_revision_status' => (string) $revision->workflow_state,
                'historical_draft_fingerprint_sha256' => $historicalFingerprint,
                'historical_snapshot_locked' => $historicalSnapshotLocked,
                'historical_draft_created_at' => $revision->created_at?->toAtomString(),
                'historical_draft_updated_at' => $revision->updated_at?->toAtomString(),
                'historical_draft_pointer_active' => $historicalWorkingPointerActive
                    || $historicalPublishedPointerActive,
                'historical_working_pointer_active' => $historicalWorkingPointerActive,
                'historical_published_pointer_active' => $historicalPublishedPointerActive,
                'historical_slot_resolution' => $historicalSnapshotLocked ? 'resolved' : 'snapshot_mismatch',
                'historical_draft_equals_current_published' => $row['published_revision_fingerprint_sha256'] !== null
                    && hash_equals($row['published_revision_fingerprint_sha256'], $historicalFingerprint),
                'historical_source_package' => (string) $revision->source_package,
                'historical_source_hash' => (string) $revision->source_hash,
                'historical_authority_package_sha256' => (string) $revision->authority_package_sha256,
                'historical_private_result_leakage' => $historicalPrivate,
                'historical_media_reference' => $historicalMedia,
                'historical_chinese_leakage' => $historicalCjk,
                'recommended_disposition' => match (true) {
                    $historicalProhibited && $historicalMayBlock => 'prohibited_content',
                    ! $historicalSnapshotLocked && $historicalMayBlock => 'blocked_authority_unknown',
                    $historicalSnapshotLocked && $mayClassifyHistoricalLineage => 'stale_working_revision',
                    default => $row['recommended_disposition'],
                },
                'blocker' => match (true) {
                    $historicalProhibited && $historicalMayBlock => 'historical_draft_prohibited_content',
                    ! $historicalSnapshotLocked && $historicalMayBlock => 'registered_historical_slot_snapshot_mismatch',
                    $historicalSnapshotLocked && $mayClassifyHistoricalLineage => null,
                    default => $row['blocker'],
                },
            ]);
        }, $rows);

        $after = $this->databaseFingerprint();
        if (! hash_equals($before, $after)) {
            throw new RuntimeException('Read-only Big Five English draft inventory changed the database.');
        }

        $dispositions = array_count_values(array_column($rows, 'recommended_disposition'));
        ksort($dispositions);

        $blockingRows = collect($rows)->filter(fn (array $row): bool => (
            ! in_array($row['recommended_disposition'], [
                'verify_only_no_action',
                'duplicate_of_published',
                'stale_working_revision',
                'valid_unpublished_candidate',
            ], true)
        ))->count();
        $hubAuthorityComplete = count($hubRows) === 2
            && collect($hubRows)->every(fn (array $row): bool => (
                $row['backend_resource_id'] !== null
                && $row['published_en52_lineage_locked'] === true
                && $row['published_en52_projection_locked'] === true
                && $row['published_projection_exists'] === true
                && $row['draft_equals_published'] === true
                && $row['schema_complete'] === true
                && $row['text_only_compliant'] === true
                && $row['claim_boundary_compliant'] === true
                && $row['chinese_leakage'] === false
                && in_array($row['recommended_disposition'], [
                    'duplicate_of_published',
                    'stale_working_revision',
                    'valid_unpublished_candidate',
                    'verify_only_no_action',
                ], true)
            ));
        $slotAuthorityComplete = count($rows) === 50
            && collect($rows)->every(fn (array $row): bool => (
                $row['backend_resource_id'] !== null
                && $row['published_en52_lineage_locked'] === true
                && $row['published_en52_projection_locked'] === true
                && $row['published_projection_exists'] === true
                && $row['schema_complete'] === true
                && $row['text_only_compliant'] === true
                && $row['claim_boundary_compliant'] === true
                && $row['chinese_leakage'] === false
                && in_array($row['recommended_disposition'], [
                    'duplicate_of_published',
                    'stale_working_revision',
                    'valid_unpublished_candidate',
                    'verify_only_no_action',
                ], true)
            ));
        $en52PackageRevisionKeys = PersonalityPublicContentAssetRevision::query()
            ->where('authority_package_sha256', BigFiveEn52Publisher::PACKAGE_FILE_SHA256)
            ->pluck('authority_asset_key')
            ->map(static fn (mixed $key): string => (string) $key)
            ->sort()
            ->values()
            ->all();
        $expectedEn52PackageRevisionKeys = array_keys(self::EN52_DESCRIPTOR_LOCKS);
        sort($expectedEn52PackageRevisionKeys);
        $en52PackageRevisionSetComplete = count($en52PackageRevisionKeys) === BigFiveEn52PackageCompiler::ASSET_COUNT
            && $en52PackageRevisionKeys === $expectedEn52PackageRevisionKeys;
        $canonicalCohortComplete = $canonical->count() === BigFiveEn52PackageCompiler::ASSET_COUNT
            && $hubAuthorityComplete
            && $slotAuthorityComplete
            && $en52PackageRevisionSetComplete
            && $aliases->isEmpty();
        $ok = $blockingRows === 0
            && $unknownAuthorityRows->isEmpty()
            && $aliases->isEmpty()
            && $canonicalCohortComplete;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => $ok,
            'status' => $ok
                ? 'PASS_BIG_FIVE_ENGLISH_DRAFT_INVENTORY_ZERO_WRITE'
                : 'BLOCKED_BIG_FIVE_ENGLISH_DRAFT_INVENTORY_ZERO_WRITE',
            'mode' => 'database_read_only_zero_write',
            'authority' => 'personality_public_content_assets_and_immutable_revisions',
            'locale' => 'en',
            'cohort_definition' => '50 registered historical slot identities from the 52-page EN52 canonical catalog, excluding model hub and facet hub',
            'canonical_cohort_complete' => $canonicalCohortComplete,
            'redirect_only_aliases_absent' => $aliases->isEmpty(),
            'en52_package_revision_set_complete' => $en52PackageRevisionSetComplete,
            'excluded_hub_authority_complete' => $hubAuthorityComplete,
            'historical_slot_authority_complete' => $slotAuthorityComplete,
            'counts' => [
                'expected_canonical_assets' => BigFiveEn52PackageCompiler::ASSET_COUNT,
                'observed_canonical_assets' => $canonical->count(),
                'expected_en52_package_revisions' => BigFiveEn52PackageCompiler::ASSET_COUNT,
                'observed_en52_package_revisions' => count($en52PackageRevisionKeys),
                'expected_excluded_hub_assets' => 2,
                'validated_excluded_hub_assets' => collect($hubRows)->filter(fn (array $row): bool => (
                    $row['published_en52_lineage_locked'] === true
                    && $row['published_en52_projection_locked'] === true
                    && $row['published_projection_exists'] === true
                    && $row['draft_equals_published'] === true
                    && $row['schema_complete'] === true
                    && $row['text_only_compliant'] === true
                    && $row['claim_boundary_compliant'] === true
                    && $row['chinese_leakage'] === false
                ))->count(),
                'historical_slots' => $expected->count(),
                'observed_slot_assets' => collect($rows)->whereNotNull('backend_resource_id')->count(),
                'historical_revision_rows' => collect($rows)->whereNotNull('historical_draft_revision_id')->count(),
                'independent_working_revisions' => collect($rows)->where('working_pointer_active', true)
                    ->where('draft_equals_published', false)->count(),
                'published_revisions' => collect($rows)->whereNotNull('published_revision_id')->count(),
                'public_projections' => collect($rows)->where('published_projection_exists', true)->count(),
                'redirect_only_alias_rows' => $aliases->count(),
                'unknown_authority_rows' => $unknownAuthorityRows->count(),
                'blocking_rows' => $blockingRows,
            ],
            'disposition_totals' => $dispositions,
            'database_snapshot_before_sha256' => $before,
            'database_snapshot_after_sha256' => $after,
            'database_snapshot_unchanged' => true,
            'writes_committed' => false,
            'excluded_hub_rows' => $hubRows,
            'rows' => $rows,
        ];
    }

    /** @param array{entity_type:string,entity_key:string,path:string} $entry
     * @return array<string,mixed>
     */
    private function row(array $entry, ?PersonalityPublicContentAsset $asset): array
    {
        $working = $asset?->working_revision_id
            ? PersonalityPublicContentAssetRevision::query()->find((int) $asset->working_revision_id)
            : null;
        $published = $asset?->published_revision_id
            ? PersonalityPublicContentAssetRevision::query()->find((int) $asset->published_revision_id)
            : null;
        $workingSnapshot = $working?->snapshot_json;
        $publishedSnapshot = $published?->snapshot_json;
        $workingContent = is_array(data_get($workingSnapshot, 'attributes'))
            ? data_get($workingSnapshot, 'attributes')
            : $workingSnapshot;
        $publishedAttributes = is_array(data_get($publishedSnapshot, 'attributes'))
            ? data_get($publishedSnapshot, 'attributes')
            : null;
        $workingFingerprint = $working ? $this->fingerprint($workingContent) : null;
        $publishedFingerprint = $published ? $this->fingerprint(
            $publishedAttributes ?? $publishedSnapshot,
        ) : null;
        $workingActive = $asset !== null && $working !== null
            && (int) $working->asset_id === (int) $asset->id;
        $publishedBound = $asset !== null && $published !== null
            && (int) $published->asset_id === (int) $asset->id;
        $en52AuthorityAssetKey = $this->en52AuthorityAssetKey($entry);
        $descriptorLock = self::EN52_DESCRIPTOR_LOCKS[$en52AuthorityAssetKey] ?? null;
        $publishedEn52Locked = $publishedBound
            && $descriptorLock !== null
            && (string) $published->source_package === BigFiveEn52PackageCompiler::RELEASE_ID
            && (string) $published->authority_package_sha256 === BigFiveEn52Publisher::PACKAGE_FILE_SHA256
            && (string) $published->workflow_state === BigFiveEn52Publisher::WORKFLOW_STATE
            && (int) $published->created_by_admin_user_id === BigFiveEn52Publisher::OPERATOR_ADMIN_USER_ID
            && (string) $published->authority_asset_key === $en52AuthorityAssetKey
            && hash_equals($descriptorLock[0], (string) $published->source_hash)
            && hash_equals($descriptorLock[1], hash('sha256', $this->stableJson($publishedSnapshot)))
            && data_get($publishedSnapshot, 'schema_version') === BigFiveEn52PackageCompiler::SCHEMA_VERSION
            && data_get($publishedSnapshot, 'release_id') === BigFiveEn52PackageCompiler::RELEASE_ID
            && data_get($publishedSnapshot, 'authority_asset_key') === $en52AuthorityAssetKey
            && data_get($publishedSnapshot, 'source_content_sha256') === BigFiveEn52PackageCompiler::SOURCE_CONTENT_SHA256
            && data_get($publishedSnapshot, 'package_file_sha256') === BigFiveEn52Publisher::PACKAGE_FILE_SHA256
            && is_array($publishedAttributes)
            && hash_equals((string) $published->source_hash, (string) ($publishedAttributes['source_hash'] ?? ''));
        $publishedProjectionLocked = $publishedEn52Locked
            && $asset !== null
            && $asset->published_at !== null
            && $this->runtimeProjectionMatches($asset, $publishedAttributes);
        $pointerEqual = $working !== null && $published !== null
            && (int) $working->id === (int) $published->id;
        $contentEqual = $workingFingerprint !== null && $publishedFingerprint !== null
            && hash_equals($workingFingerprint, $publishedFingerprint);
        $newer = $working !== null && $published !== null
            && (int) $working->revision_no > (int) $published->revision_no;
        $independentWorkingCandidate = $working !== null
            && ($published === null || (! $pointerEqual && $newer));
        $payload = json_encode($workingSnapshot ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $cjk = $this->containsCjk($payload);
        $private = $this->containsProhibitedPrivateField($workingSnapshot);
        $legacyAlias = $this->containsLegacyAliasReference($workingSnapshot);
        $titleComplete = is_string(data_get($workingContent, 'title'))
            && trim(data_get($workingContent, 'title')) !== '';
        $summaryComplete = is_string(data_get($workingContent, 'summary'))
            && trim(data_get($workingContent, 'summary')) !== '';
        $sections = data_get($workingContent, 'content_sections_json');
        $faq = data_get($workingContent, 'faq_json');
        $sectionsComplete = is_array($sections)
            && (! $independentWorkingCandidate || $this->sectionsComplete($sections));
        $faqComplete = is_array($faq)
            && (! $independentWorkingCandidate || $this->faqComplete($faq));
        $schemaComplete = $working !== null
            && $titleComplete
            && $summaryComplete
            && $sectionsComplete
            && $faqComplete;
        $textOnly = ! $this->containsMediaReference($workingSnapshot);
        $candidateWorkflowDraft = ! $independentWorkingCandidate
            || (string) $working->workflow_state === PersonalityPublicContentAssetRevision::STATE_DRAFT;
        $candidateIdentityMatches = ! $independentWorkingCandidate
            || ($asset !== null && $this->candidateIdentityMatches(
                $workingSnapshot,
                $workingContent,
                $asset,
                $entry,
            ));
        $projection = $publishedProjectionLocked && $asset !== null && (bool) $asset->is_public
            && in_array($asset->launch_state, [
                PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
                PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            ], true)
            && $asset->published_at !== null
            && ! $asset->published_at->isFuture();
        $translationGroupId = data_get($asset?->authority_json, 'translation_group_id');
        $translationGroupIdSafe = is_string($translationGroupId)
            && preg_match('/^big-five:[a-z0-9:_-]{1,128}$/', $translationGroupId) === 1;
        $workingRevisionStatus = $working === null ? null : (string) $working->workflow_state;
        $workingRevisionStatusSafe = $workingRevisionStatus === null || in_array($workingRevisionStatus, [
            PersonalityPublicContentAssetRevision::STATE_DRAFT,
            'pending_manual_review',
            BigFiveEn52Publisher::WORKFLOW_STATE,
        ], true);

        $disposition = match (true) {
            $asset === null || ! $workingActive || ($asset->published_revision_id && ! $publishedBound) => 'blocked_authority_unknown',
            $cjk || $private || $legacyAlias || ! $textOnly => 'prohibited_content',
            ! $candidateIdentityMatches => 'translation_identity_mismatch',
            ! $schemaComplete => 'schema_repair_required',
            ! $candidateWorkflowDraft => 'schema_repair_required',
            $published !== null && ! $publishedEn52Locked => 'blocked_authority_unknown',
            $published !== null && ! $publishedProjectionLocked => 'blocked_authority_unknown',
            $contentEqual => 'duplicate_of_published',
            $published !== null && ! $newer => 'stale_working_revision',
            $working !== null && $published === null => 'valid_unpublished_candidate',
            $working !== null && $published !== null && ! $pointerEqual && $newer => 'valid_unpublished_candidate',
            default => 'verify_only_no_action',
        };

        return [
            'backend_resource_id' => $asset?->id,
            'logical_identity' => $entry['entity_type'].':'.$entry['entity_key'],
            'entity_type' => $entry['entity_type'],
            'entity_key' => $entry['entity_key'],
            'locale' => 'en',
            'translation_group_id' => $translationGroupIdSafe ? $translationGroupId : null,
            'translation_group_id_safe' => $translationGroupIdSafe,
            'working_revision_id' => $working?->id,
            'working_revision_status' => $workingRevisionStatusSafe
                ? $workingRevisionStatus
                : 'invalid_unrecognized',
            'working_revision_status_safe' => $workingRevisionStatusSafe,
            'working_revision_fingerprint_sha256' => $workingFingerprint,
            'published_revision_id' => $published?->id,
            'published_revision_fingerprint_sha256' => $publishedFingerprint,
            'published_en52_lineage_locked' => $publishedEn52Locked,
            'published_en52_projection_locked' => $publishedProjectionLocked,
            'draft_created_at' => $working?->created_at?->toAtomString(),
            'draft_updated_at' => $working?->updated_at?->toAtomString(),
            'published_projection_exists' => $projection,
            'working_pointer_active' => $workingActive,
            'public_page_accessible' => $projection && filled($entry['path']),
            'draft_equals_published' => $pointerEqual,
            'draft_content_equals_published' => $contentEqual,
            'draft_newer_than_published' => $newer,
            'schema_complete' => $schemaComplete,
            'title_complete' => $titleComplete,
            'summary_complete' => $summaryComplete,
            'candidate_workflow_draft' => $candidateWorkflowDraft,
            'candidate_identity_matches' => $candidateIdentityMatches,
            'sections_complete' => $sectionsComplete,
            'faq_complete' => $faqComplete,
            'text_only_compliant' => $textOnly,
            'claim_boundary_compliant' => ! $private,
            'duplicate_template_risk' => $contentEqual ? 'published_equivalent' : 'not_established',
            'chinese_leakage' => $cjk,
            'private_result_leakage' => $private,
            'legacy_alias_reference' => $legacyAlias,
            'recommended_disposition' => $disposition,
            'current_revision_disposition' => $disposition,
            'source_evidence' => [
                'asset_table' => 'personality_public_content_assets',
                'revision_table' => 'personality_public_content_asset_revisions',
                'canonical_path' => $entry['path'],
            ],
            'blocker' => $disposition === 'blocked_authority_unknown'
                ? ($published !== null && ! $publishedEn52Locked
                    ? 'current_published_revision_not_locked_en52_authority'
                    : ($published !== null && ! $publishedProjectionLocked
                        ? 'live_asset_not_locked_en52_projection'
                        : 'current_revision_authority_incomplete'))
                : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @param  array<string,mixed>  $content
     * @param  array{entity_type:string,entity_key:string,path:string}  $entry
     */
    private function candidateIdentityMatches(
        array $snapshot,
        array $content,
        PersonalityPublicContentAsset $asset,
        array $entry,
    ): bool {
        $expected = [
            'org_id' => 0,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'entity_type' => $entry['entity_type'],
            'entity_key' => $entry['entity_key'],
            'locale' => 'en',
            'slug' => (string) $asset->slug,
            'canonical_json.path' => $entry['path'],
        ];

        foreach ($expected as $key => $value) {
            $missing = new \stdClass;
            $actual = data_get($content, $key, $missing);
            if ($actual === $missing || $actual !== $value) {
                return false;
            }

            if ($snapshot !== $content) {
                $envelopeValue = data_get($snapshot, $key, $missing);
                if ($envelopeValue !== $missing && $envelopeValue !== $value) {
                    return false;
                }
            }
        }

        return true;
    }

    private function sectionsComplete(mixed $sections): bool
    {
        if (! is_array($sections) || $sections === [] || ! array_is_list($sections)) {
            return false;
        }

        foreach ($sections as $section) {
            if (! is_array($section)
                || ! $this->nonEmptyString($section['key'] ?? null)
                || ! $this->nonEmptyString($section['kind'] ?? null)
                || ! $this->nonEmptyString($section['heading'] ?? null)
                || ! $this->nonEmptyString($section['body'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function faqComplete(mixed $faq): bool
    {
        if (! is_array($faq) || $faq === [] || ! array_is_list($faq)) {
            return false;
        }

        foreach ($faq as $item) {
            if (! is_array($item)
                || ! $this->nonEmptyString($item['question'] ?? null)
                || ! $this->nonEmptyString($item['answer'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /** @param array<string,mixed> $expected */
    private function runtimeProjectionMatches(
        PersonalityPublicContentAsset $asset,
        array $expected,
    ): bool {
        foreach ($expected as $key => $value) {
            if ($this->stableJson($this->comparable($asset->getAttribute($key)))
                !== $this->stableJson($this->comparable($value))) {
                return false;
            }
        }

        return true;
    }

    private function comparable(mixed $value): mixed
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
    }

    private function stableJson(mixed $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (array_is_list($item)) {
                return array_map($normalize, $item);
            }
            ksort($item);
            foreach ($item as $key => $child) {
                $item[$key] = $normalize($child);
            }

            return $item;
        };

        return json_encode(
            $normalize($value),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n";
    }

    private function databaseFingerprint(): string
    {
        foreach (['personality_public_content_assets', 'personality_public_content_asset_revisions'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Required authority table {$table} is missing.");
            }
        }

        return $this->fingerprint([
            'assets' => DB::table('personality_public_content_assets')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'revisions' => DB::table('personality_public_content_asset_revisions')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        ]);
    }

    private function fingerprint(mixed $value): string
    {
        return hash('sha256', $this->stableJson($value));
    }

    private function containsProhibitedPrivateField(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $normalizedKey = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $key);
                $normalizedKey = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', (string) $normalizedKey);
                $normalizedKey = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', (string) $normalizedKey));
                if (in_array($normalizedKey, BigFiveResultPageV2SelectorAssetContract::FORBIDDEN_PUBLIC_FIELDS, true)
                    || in_array($normalizedKey, BigFiveResultPageV2Contract::FORBIDDEN_PUBLIC_FIELDS, true)
                    || in_array($normalizedKey, BigFiveResultPageV2Contract::SHARE_FORBIDDEN_SCORE_FIELDS, true)
                    || $normalizedKey === 'private_path'
                    || preg_match(
                        '/^(?:answers|attempt(?:_id|_uuid)?|draft_snapshot|generated_authority_package|'
                            .'order(?:_id)?|payment(?:_id)?|private_url|recovery_token|report_(?:token|url)|'
                            .'review_snapshot|selector_trace|snapshot_json|user_id|working_revision_payload)$/',
                        $normalizedKey,
                    ) === 1) {
                    return true;
                }
            }
            if ($this->containsProhibitedPrivateField($item)) {
                return true;
            }
        }

        return false;
    }

    private function containsMediaReference(mixed $value): bool
    {
        if (is_string($value)) {
            if (preg_match('/<(?:img|picture|source)\b/i', $value) === 1) {
                return true;
            }
            preg_match_all('/!\[[^\]]*\]/', $value, $tokens, PREG_OFFSET_CAPTURE);
            foreach ($tokens[0] as [, $offset]) {
                $precedingBackslashes = 0;
                for ($index = $offset - 1; $index >= 0 && $value[$index] === '\\'; $index--) {
                    $precedingBackslashes++;
                }
                if ($precedingBackslashes % 2 === 0) {
                    return true;
                }
            }

            return false;
        }
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $key => $item) {
            if (is_string($key)
                && preg_match('/(?:image|media|hero|og_image|twitter_image)/i', $key) === 1) {
                return true;
            }
            if ($this->containsMediaReference($item)) {
                return true;
            }
        }

        return false;
    }

    private function containsLegacyAliasReference(mixed $value): bool
    {
        if (is_string($value)) {
            foreach (array_keys(BigFiveCanonicalRouteCatalog::EN_REDIRECT_ONLY_ALIAS_TARGETS) as $alias) {
                if (preg_match(
                    '~(?:^|[/\s"\'(])'.preg_quote($alias, '~').'(?=$|[/\s"\'?#)])~i',
                    $value,
                ) === 1) {
                    return true;
                }
            }

            return false;
        }
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $item) {
            if ($this->containsLegacyAliasReference($item)) {
                return true;
            }
        }

        return false;
    }

    private function containsCjk(string $value): bool
    {
        return preg_match('/\p{Han}/u', $value) === 1;
    }

    /** @param array<string,mixed> $entry */
    private function en52AuthorityAssetKey(array $entry): string
    {
        if ($entry['entity_type'] === PersonalityPublicContentAsset::ENTITY_FACET_DETAIL) {
            return self::FACET_EN52_AUTHORITY_KEY[(string) $entry['entity_key']] ?? '';
        }
        if ($entry['entity_type'] === PersonalityPublicContentAsset::ENTITY_HUB) {
            return $entry['entity_key'] === 'big-five' ? 'big-five-hub' : '';
        }

        return (string) $entry['entity_key'];
    }

    /** @param array<string,mixed> $row */
    private function isRegisteredHistoricalSlotRevision(
        PersonalityPublicContentAssetRevision $revision,
        array $row,
    ): bool {
        $identity = $this->historicalSlotIdentity(
            (string) $row['entity_type'],
            (string) $row['entity_key'],
        );

        return $identity !== null
            && (string) $revision->source_package === $identity['source_package']
            && (string) $revision->workflow_state === PersonalityPublicContentAssetRevision::STATE_DRAFT
            && (string) $revision->authority_asset_key === $identity['authority_asset_key']
            && isset(self::HISTORICAL_SOURCE_HASHES[$row['logical_identity']])
            && hash_equals(
                self::HISTORICAL_SOURCE_HASHES[$row['logical_identity']],
                (string) $revision->source_hash,
            )
            && (string) $revision->authority_package_sha256 === self::HISTORICAL_AUTHORITY_PACKAGE_SHA256;
    }

    /** @return array{source_package:string,authority_asset_key:string}|null */
    private function historicalSlotIdentity(string $entityType, string $entityKey): ?array
    {
        if ($entityType === PersonalityPublicContentAsset::ENTITY_DOMAIN
            && in_array($entityKey, BigFiveCanonicalRouteCatalog::DOMAINS, true)) {
            return [
                'source_package' => 'big5-authority-v2-domains-08',
                'authority_asset_key' => 'domain:'.$entityKey,
            ];
        }
        if ($entityType === PersonalityPublicContentAsset::ENTITY_POLARITY) {
            [$domain, $range] = array_pad(explode('-', $entityKey, 2), 2, null);
            if (isset(self::HISTORICAL_PACKAGE_BY_DOMAIN[$domain])
                && in_array($range, ['high', 'mid', 'low'], true)) {
                return [
                    'source_package' => self::HISTORICAL_PACKAGE_BY_DOMAIN[$domain],
                    'authority_asset_key' => "range:{$domain}:{$range}",
                ];
            }
        }
        if ($entityType === PersonalityPublicContentAsset::ENTITY_FACET_DETAIL) {
            $domain = self::FACET_DOMAIN[$entityKey] ?? null;
            $batch = [
                'openness' => 15,
                'conscientiousness' => 16,
                'extraversion' => 17,
                'agreeableness' => 18,
                'neuroticism' => 19,
            ][$domain] ?? null;
            if ($domain !== null && $batch !== null) {
                return [
                    'source_package' => "big5-authority-v2-facets-{$domain}-{$batch}",
                    'authority_asset_key' => "facet:{$domain}:{$entityKey}",
                ];
            }
        }

        return null;
    }
}
