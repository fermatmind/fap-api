<?php

declare(strict_types=1);

namespace App\Services\Career\Directory;

use Illuminate\Support\Str;

final class OccupationDirectoryDisplayNormalizer
{
    /**
     * @var array<string, array{en:string, zh:string}>
     */
    private const FAMILY_LABELS = [
        'architecture-and-engineering' => ['en' => 'Architecture and engineering', 'zh' => '建筑与工程'],
        'arts-and-design' => ['en' => 'Arts and design', 'zh' => '艺术与设计'],
        'building-and-grounds-cleaning' => ['en' => 'Building and grounds cleaning', 'zh' => '建筑维护与清洁'],
        'business-and-financial' => ['en' => 'Business and financial', 'zh' => '商业与金融'],
        'community-and-social-service' => ['en' => 'Community and social service', 'zh' => '社区与社会服务'],
        'computer-and-information-technology' => ['en' => 'Computer and information technology', 'zh' => '计算机与信息技术'],
        'construction-and-extraction' => ['en' => 'Construction and extraction', 'zh' => '建筑施工与采掘'],
        'education-training-and-library' => ['en' => 'Education, training, and library', 'zh' => '教育、培训与图书馆'],
        'entertainment-and-sports' => ['en' => 'Entertainment and sports', 'zh' => '娱乐与体育'],
        'farming-fishing-and-forestry' => ['en' => 'Farming, fishing, and forestry', 'zh' => '农业、渔业与林业'],
        'food-preparation-and-serving' => ['en' => 'Food preparation and serving', 'zh' => '餐饮服务'],
        'healthcare' => ['en' => 'Healthcare', 'zh' => '医疗健康'],
        'installation-maintenance-and-repair' => ['en' => 'Installation, maintenance, and repair', 'zh' => '安装、维护与修理'],
        'legal' => ['en' => 'Legal', 'zh' => '法律'],
        'life-physical-and-social-science' => ['en' => 'Life, physical, and social science', 'zh' => '生命、物理与社会科学'],
        'management' => ['en' => 'Management', 'zh' => '管理'],
        'math' => ['en' => 'Math', 'zh' => '数学与精算'],
        'media-and-communication' => ['en' => 'Media and communication', 'zh' => '媒体与传播'],
        'military' => ['en' => 'Military', 'zh' => '军事'],
        'office-and-administrative-support' => ['en' => 'Office and administrative support', 'zh' => '办公室与行政支持'],
        'personal-care-and-service' => ['en' => 'Personal care and service', 'zh' => '个人护理与服务'],
        'production' => ['en' => 'Production', 'zh' => '生产制造'],
        'protective-service' => ['en' => 'Protective service', 'zh' => '安全与保护服务'],
        'sales' => ['en' => 'Sales', 'zh' => '销售'],
        'transportation-and-material-moving' => ['en' => 'Transportation and material moving', 'zh' => '运输与物料搬运'],
        '__unknown__' => ['en' => 'Other tracked occupations', 'zh' => '其他职业'],
    ];

    /**
     * @var array<string, string>
     */
    private const US_MAJOR_FAMILY = [
        '11' => 'management',
        '13' => 'business-and-financial',
        '15' => 'computer-and-information-technology',
        '17' => 'architecture-and-engineering',
        '19' => 'life-physical-and-social-science',
        '21' => 'community-and-social-service',
        '23' => 'legal',
        '25' => 'education-training-and-library',
        '29' => 'healthcare',
        '31' => 'healthcare',
        '33' => 'protective-service',
        '35' => 'food-preparation-and-serving',
        '37' => 'building-and-grounds-cleaning',
        '39' => 'personal-care-and-service',
        '41' => 'sales',
        '43' => 'office-and-administrative-support',
        '45' => 'farming-fishing-and-forestry',
        '47' => 'construction-and-extraction',
        '49' => 'installation-maintenance-and-repair',
        '51' => 'production',
        '53' => 'transportation-and-material-moving',
        '55' => 'military',
    ];

    /**
     * @var array<string, string>
     */
    private const CN_MAJOR_FAMILY = [
        '1' => 'management',
        '3' => 'office-and-administrative-support',
        '5' => 'farming-fishing-and-forestry',
        '6' => 'production',
        '7' => 'military',
    ];

    /**
     * @var array<string, string>
     */
    private const EXACT_CN_TRANSLATIONS = [
        '计算机程序设计员' => 'Computer Programmers',
        '计算机维修工' => 'Computer Repairers',
        '计算机网络工程技术人员' => 'Computer Network Engineering Technicians',
        '计算机软件工程技术人员' => 'Software Engineering Technicians',
        '计算机软件测试员' => 'Software Testers',
        '计量工程技术人员' => 'Metrology Engineering Technicians',
        '计量员' => 'Measurement Technicians',
        '害虫防治员' => 'Pest Control Workers',
        '药剂师' => 'Pharmacists',
        '药房技术员' => 'Pharmacy Technicians',
        '摄影师' => 'Photographers',
        '讲解员' => 'Tour Guides',
        '石油工程师' => 'Petroleum Engineers',
    ];

    /**
     * @var array<string, string>
     */
    private const CN_TERM_TRANSLATIONS = [
        '中国共产党' => 'Chinese Communist Party',
        '基层组织' => 'grassroots organization',
        '国家权力机关' => 'state authority',
        '国家行政机关' => 'state administrative agency',
        '人民政协' => 'CPPCC',
        '监察机关' => 'supervisory agency',
        '人民法院' => 'people\'s court',
        '人民检察院' => 'people\'s procuratorate',
        '计算机软件' => 'software',
        '计算机网络' => 'computer network',
        '计算机程序' => 'computer programming',
        '计算机' => 'computer',
        '互联网' => 'internet',
        '信息系统' => 'information systems',
        '信息安全' => 'information security',
        '人工智能' => 'artificial intelligence',
        '大数据' => 'big data',
        '数据库' => 'database',
        '网络' => 'network',
        '软件' => 'software',
        '硬件' => 'hardware',
        '程序' => 'programming',
        '通信' => 'communications',
        '电子' => 'electronics',
        '电气' => 'electrical',
        '机械' => 'mechanical',
        '建筑' => 'building',
        '土木' => 'civil',
        '工程' => 'engineering',
        '计量' => 'metrology',
        '测量' => 'surveying',
        '质量' => 'quality',
        '安全' => 'safety',
        '环境' => 'environmental',
        '化学' => 'chemical',
        '生物' => 'biological',
        '医学' => 'medical',
        '医疗' => 'healthcare',
        '护理' => 'nursing',
        '药品' => 'pharmaceutical',
        '药物' => 'pharmaceutical',
        '药剂' => 'pharmacy',
        '康复' => 'rehabilitation',
        '教育' => 'education',
        '教师' => 'teacher',
        '会计' => 'accounting',
        '审计' => 'audit',
        '金融' => 'financial',
        '财务' => 'financial',
        '销售' => 'sales',
        '营销' => 'marketing',
        '物流' => 'logistics',
        '运输' => 'transportation',
        '航空' => 'aviation',
        '铁路' => 'railway',
        '汽车' => 'automotive',
        '船舶' => 'marine',
        '食品' => 'food',
        '餐饮' => 'food service',
        '农业' => 'agricultural',
        '林业' => 'forestry',
        '畜牧' => 'animal husbandry',
        '渔业' => 'fishery',
        '法律' => 'legal',
        '律师' => 'lawyer',
        '媒体' => 'media',
        '新闻' => 'news',
        '出版' => 'publishing',
        '影视' => 'film and television',
        '广告' => 'advertising',
        '艺术' => 'art',
        '音乐' => 'music',
        '体育' => 'sports',
        '旅游' => 'tourism',
        '物业' => 'property management',
        '清洁' => 'cleaning',
        '家政' => 'domestic service',
        '安保' => 'security',
        '消防' => 'fire protection',
        '警务' => 'policing',
        '维修' => 'repair',
        '维护' => 'maintenance',
        '安装' => 'installation',
        '检测' => 'inspection and testing',
        '检验' => 'inspection',
        '测试' => 'testing',
        '设计' => 'design',
        '制造' => 'manufacturing',
        '生产' => 'production',
        '加工' => 'processing',
        '操作' => 'operation',
        '装配' => 'assembly',
        '管理' => 'management',
        '行政' => 'administrative',
        '社会组织' => 'social organization',
        '企事业单位' => 'enterprise and institution',
        '机关' => 'agency',
        '负责人' => 'head',
        '技术' => 'technical',
        '专业' => 'professional',
        '数据' => 'data',
        '系统' => 'systems',
        '仪器' => 'instrument',
        '设备' => 'equipment',
        '防治' => 'control',
        '害虫' => 'pest',
    ];

    /**
     * @var array<string, string>
     */
    private const EXACT_US_ZH_TRANSLATIONS = [
        'Audio and Video Technicians' => '音视频技术员',
        'Broadcast Announcers and Radio Disc Jockeys' => '广播主持人与电台唱片骑师',
        'Broadcast Technicians' => '广播技术员',
        'Camera Operators, Television, Video, and Film' => '电视、视频和电影摄像操作员',
        'Chief Sustainability Officers' => '首席可持续发展官',
        'Choreographers' => '编舞师',
        'Dancers' => '舞者',
        'Disc Jockeys, Except Radio' => '非电台唱片骑师',
        'Film and Video Editors' => '影视剪辑师',
        'Lighting Technicians' => '灯光技术员',
        'Merchandise Displayers and Window Trimmers' => '商品陈列与橱窗布置员',
        'Sound Engineering Technicians' => '音响工程技术员',
        'Talent Directors' => '选角与艺人总监',
        'Acupuncturists' => '针灸师',
        'Tile And Stone Setters' => '瓷砖和石材铺设工',
        'Timing Device Assemblers And Adjusters' => '计时装置装配与调校工',
        'Tire Builders' => '轮胎制造工',
        'Tire Repairers And Changers' => '轮胎修理与更换工',
        'Title Examiners, Abstractors, And Searchers' => '产权审查、摘要与检索员',
        'Tool And Die Makers' => '工具和模具制造工',
        'Tool Grinders, Filers, And Sharpeners' => '工具磨削、锉削与刃磨工',
        'Tour Guides And Escorts' => '导游和陪同人员',
    ];

    /**
     * @var array<string, string>
     */
    private const EN_PHRASE_ZH_TRANSLATIONS = [
        'adult basic education' => '成人基础教育',
        'adult secondary education' => '成人中等教育',
        'english as a second language' => '英语作为第二语言',
        'air crew' => '机组',
        'aircraft cargo handling' => '飞机货物装卸',
        'aircraft service' => '飞机服务',
        'aircraft structure' => '飞机结构',
        'armored assault vehicle' => '装甲突击车辆',
        'audio visual' => '视听',
        'automotive and watercraft' => '汽车与船艇',
        'automotive glass' => '汽车玻璃',
        'baggage porters' => '行李搬运员',
        'bench carpenters' => '台架木工',
        'brickmasons and blockmasons' => '砖石与砌块工',
        'bus drivers' => '公交司机',
        'cargo and freight' => '货物与货运',
        'cement masons' => '水泥泥瓦工',
        'clinical and counseling' => '临床与咨询',
        'clinical research' => '临床研究',
        'coin vending and amusement machine' => '投币、自动售货与娱乐机器',
        'control and valve' => '控制与阀门',
        'court municipal and license' => '法院、市政与执照',
        'credit authorizers' => '信贷授权员',
        'dining room and cafeteria' => '餐厅与食堂',
        'drywall and ceiling tile' => '石膏板与天花板瓷砖',
        'earth drillers' => '土方钻探工',
        'education administrators' => '教育行政管理者',
        'electric motor' => '电机',
        'electrical and electronic equipment' => '电气与电子设备',
        'electrical and electronics' => '电气与电子',
        'electronic equipment' => '电子设备',
        'emergency medicine' => '急诊医学',
        'engine and other machine' => '发动机和其他机器',
        'environmental compliance' => '环境合规',
        'environmental restoration' => '环境修复',
        'exercise trainers' => '健身训练师',
        'fabric and apparel' => '织物与服装',
        'family medicine' => '家庭医学',
        'farm and home management' => '农场与家庭管理',
        'farm labor' => '农场劳务',
        'farmworkers and laborers' => '农场工人与劳工',
        'farm ranch and aquacultural animals' => '农场、牧场与水产养殖动物',
        'fiberglass laminators' => '玻璃纤维层压工',
        'fire inspectors' => '消防检查员',
        'first line supervisors' => '一线主管',
        'fish and game' => '鱼类与野生动物',
        'fitness and wellness' => '健身与健康',
        'floor layers' => '地板铺设工',
        'floor sanders' => '地板打磨工',
        'foundry mold' => '铸造模具',
        'freight forwarders' => '货运代理',
        'funeral attendants' => '殡葬服务员',
        'gambling and sports book' => '博彩与体育投注',
        'general internal medicine' => '普通内科',
        'glass blowers' => '玻璃吹制工',
        'government property' => '政府财产',
        'graders and sorters' => '分级与分拣员',
        'hard tiles' => '硬质瓷砖',
        'home appliance' => '家电',
        'hotel motel and resort' => '酒店、汽车旅馆与度假村',
        'industrial organizational' => '工业组织',
        'insurance claims' => '保险理赔',
        'judicial law' => '司法法律',
        'laborers and freight' => '劳工与货运',
        'light truck' => '轻型卡车',
        'loan interviewers' => '贷款面谈员',
        'locker room coatroom and dressing room' => '更衣室、衣帽间与化妆间',
        'log graders' => '原木分级员',
        'machine feeders' => '机器送料工',
        'manufactured building' => '预制建筑',
        'mathematical science occupations' => '数学科学职业',
        'mechanical door' => '机械门',
        'meter readers' => '抄表员',
        'military officer special and tactical operations' => '军官特种与战术行动',
        'mobile heavy equipment' => '移动重型设备',
        'model makers' => '模型制造工',
        'molecular and cellular' => '分子与细胞',
        'morticians undertakers and funeral arrangers' => '殡葬师、殡仪承办人与葬礼安排员',
        'motion picture' => '电影',
        'musical instrument' => '乐器',
        'new accounts' => '新账户',
        'obstetricians and gynecologists' => '妇产科医师',
        'occupational therapy' => '作业治疗',
        'office clerks' => '办公室文员',
        'online merchants' => '在线商户',
        'outdoor power equipment' => '户外动力设备',
        'packers and packagers' => '包装工',
        'park naturalists' => '公园自然解说员',
        'passenger attendants' => '乘客服务员',
        'patient representatives' => '患者代表',
        'payroll and timekeeping' => '薪资与考勤',
        'pesticide handlers' => '农药处理工',
        'physical medicine and rehabilitation' => '物理医学与康复',
        'postal service' => '邮政服务',
        'power distributors' => '电力调度分配员',
        'precision instrument and equipment' => '精密仪器与设备',
        'preventive medicine' => '预防医学',
        'proofreaders and copy markers' => '校对与文稿标记员',
        'psychiatric aides' => '精神科助理',
        'rail car' => '铁路车辆',
        'railroad conductors' => '铁路列车长',
        'real estate' => '房地产',
        'recyclable material' => '可回收材料',
        'refractory materials' => '耐火材料',
        'residential advisors' => '住宿顾问',
        'retail salespersons' => '零售销售员',
        'rock splitters' => '岩石劈裂工',
        'roof bolters' => '矿山顶板锚杆工',
        'sailors and marine oilers' => '水手与船舶机油员',
        'school bus' => '校车',
        'school psychologists' => '学校心理学家',
        'septic tank' => '化粪池',
        'sewer pipe' => '下水管道',
        'shipping receiving and inventory' => '发运、收货与库存',
        'shuttle drivers' => '穿梭车司机',
        'signal and track switch' => '信号与轨道道岔',
        'slaughterers and meat packers' => '屠宰与肉类包装工',
        'sports medicine' => '运动医学',
        'stockers and order fillers' => '补货与订单拣货员',
        'structural metal' => '结构金属',
        'substance abuse' => '物质滥用',
        'behavioral disorder' => '行为障碍',
        'tank car truck and ship' => '罐车、卡车与船舶',
        'tax preparers' => '税务申报员',
        'team assemblers' => '团队装配工',
        'transit and railroad police' => '公交与铁路警察',
        'treasurers and controllers' => '司库与财务控制员',
        'ushers lobby attendants and ticket takers' => '引座员、大堂服务员与检票员',
        'watch and clock' => '钟表',
        'web administrators' => '网站管理员',
        'weighers measurers checkers and samplers' => '称重、测量、核查与采样员',
        'wellhead pumpers' => '井口泵操作员',
        'wholesale and retail buyers' => '批发与零售采购员',
        'word processors and typists' => '文字处理员与打字员',
    ];

    /**
     * @var array<string, string>
     */
    private const EN_TERM_ZH_TRANSLATIONS = [
        'chief' => '首席',
        'executive' => '执行',
        'executives' => '执行官',
        'sustainability' => '可持续发展',
        'officers' => '官',
        'managers' => '经理',
        'manager' => '经理',
        'analysts' => '分析师',
        'analyst' => '分析师',
        'specialists' => '专员',
        'specialist' => '专员',
        'technicians' => '技术员',
        'technician' => '技术员',
        'technologists' => '技术专家',
        'engineers' => '工程师',
        'engineer' => '工程师',
        'operators' => '操作员',
        'operator' => '操作员',
        'workers' => '工人',
        'worker' => '工人',
        'assistants' => '助理',
        'assistant' => '助理',
        'teachers' => '教师',
        'teacher' => '教师',
        'designers' => '设计师',
        'designer' => '设计师',
        'artists' => '艺术家',
        'artist' => '艺术家',
        'editors' => '编辑',
        'editor' => '编辑',
        'photographers' => '摄影师',
        'photographer' => '摄影师',
        'accountants' => '会计师',
        'auditors' => '审计师',
        'lawyers' => '律师',
        'legal' => '法律',
        'health' => '健康',
        'medical' => '医疗',
        'nurses' => '护士',
        'nurse' => '护士',
        'pharmacy' => '药房',
        'computer' => '计算机',
        'software' => '软件',
        'network' => '网络',
        'data' => '数据',
        'information' => '信息',
        'security' => '安全',
        'financial' => '金融',
        'business' => '商业',
        'marketing' => '营销',
        'sales' => '销售',
        'public' => '公共',
        'relations' => '关系',
        'administrative' => '行政',
        'services' => '服务',
        'facilities' => '设施',
        'construction' => '建筑施工',
        'maintenance' => '维护',
        'repair' => '修理',
        'installation' => '安装',
        'production' => '生产',
        'manufacturing' => '制造',
        'transportation' => '运输',
        'food' => '食品',
        'preparation' => '制作',
        'serving' => '服务',
        'broadcast' => '广播',
        'announcers' => '主持人',
        'radio' => '电台',
        'disc' => '唱片',
        'jockeys' => '唱片骑师',
        'audio' => '音频',
        'video' => '视频',
        'camera' => '摄像',
        'television' => '电视',
        'film' => '电影',
        'lighting' => '灯光',
        'sound' => '音响',
        'engineering' => '工程',
        'talent' => '艺人',
        'directors' => '总监',
        'director' => '总监',
        'displayers' => '陈列员',
        'window' => '橱窗',
        'trimmers' => '布置员',
        'merchandise' => '商品',
        'abstractors' => '摘要员',
        'accounts' => '账户',
        'acupuncturists' => '针灸师',
        'adult' => '成人',
        'advisors' => '顾问',
        'aides' => '助理',
        'air' => '航空',
        'aircraft' => '飞机',
        'allergists' => '过敏专科医师',
        'ambulance' => '救护车',
        'and' => '和',
        'anesthesiologists' => '麻醉医师',
        'animal' => '动物',
        'animals' => '动物',
        'apparel' => '服装',
        'applicators' => '施用工',
        'appraisers' => '评估师',
        'architects' => '建筑师',
        'architectural' => '建筑',
        'archivists' => '档案管理员',
        'arrangers' => '安排员',
        'artillery' => '火炮',
        'assemblers' => '装配工',
        'assessors' => '评估员',
        'attendants' => '服务员',
        'authorizers' => '授权员',
        'aviation' => '航空',
        'bailiffs' => '法警',
        'barbers' => '理发师',
        'baristas' => '咖啡师',
        'basic' => '基础',
        'behavioral' => '行为',
        'bellhops' => '行李员',
        'benders' => '弯曲工',
        'bicycle' => '自行车',
        'billing' => '账单',
        'biologists' => '生物学家',
        'biostatisticians' => '生物统计学家',
        'blockmasons' => '砌块工',
        'blowers' => '吹制工',
        'bolters' => '锚杆工',
        'book' => '投注',
        'booth' => '柜台',
        'breeders' => '饲养员',
        'brickmasons' => '砌砖工',
        'bridge' => '桥梁',
        'brokers' => '经纪人',
        'brokerage' => '经纪',
        'builders' => '制造工',
        'bus' => '公交',
        'butchers' => '屠宰工',
        'buyers' => '采购员',
        'cabinetmakers' => '橱柜制造工',
        'cafeteria' => '食堂',
        'car' => '车辆',
        'carpenters' => '木工',
        'carpet' => '地毯',
        'carriers' => '投递员',
        'casters' => '铸造工',
        'caretakers' => '照护员',
        'cellular' => '细胞',
        'cement' => '水泥',
        'changers' => '更换工',
        'change' => '兑换',
        'cashiers' => '收银员',
        'chauffeurs' => '专车司机',
        'checkers' => '核查员',
        'chemists' => '化学家',
        'childcare' => '儿童照护',
        'civil' => '土木',
        'clergy' => '神职人员',
        'clerks' => '文员',
        'clinical' => '临床',
        'clock' => '钟表',
        'cleaners' => '清洁工',
        'coach' => '客车',
        'coffee' => '咖啡',
        'coil' => '线圈',
        'collectors' => '收集员',
        'commercial' => '商业',
        'compliance' => '合规',
        'concrete' => '混凝土',
        'conductors' => '列车长',
        'controllers' => '控制员',
        'coordinators' => '协调员',
        'coremakers' => '型芯工',
        'coroners' => '验尸官',
        'correspondence' => '通信',
        'costume' => '服装',
        'counseling' => '咨询',
        'counselors' => '咨询师',
        'counter' => '柜台',
        'couriers' => '快递员',
        'court' => '法院',
        'creative' => '创意',
        'credit' => '信贷',
        'criminal' => '刑事',
        'crop' => '作物',
        'crossing' => '交通路口',
        'curators' => '策展人',
        'custom' => '定制',
        'customs' => '海关',
        'cutters' => '切割工',
        'cytotechnologists' => '细胞技术专家',
        'daycare' => '日托',
        'dealers' => '发牌员',
        'demonstrators' => '演示员',
        'dentists' => '牙医',
        'dermatologists' => '皮肤科医师',
        'detectives' => '侦探',
        'die' => '模具',
        'diet' => '模具',
        'dining' => '餐厅',
        'dispatchers' => '调度员',
        'dispensing' => '配镜',
        'dishwashers' => '洗碗工',
        'disorder' => '障碍',
        'distributors' => '分配员',
        'divers' => '潜水员',
        'door' => '门',
        'drafters' => '制图员',
        'drillers' => '钻探工',
        'drivers' => '司机',
        'drywall' => '石膏板',
        'earth' => '土方',
        'ecologists' => '生态学家',
        'economists' => '经济学家',
        'education' => '教育',
        'educators' => '教育者',
        'electric' => '电动',
        'electrical' => '电气',
        'electromechanical' => '机电',
        'electronic' => '电子',
        'electronics' => '电子',
        'eligibility' => '资格',
        'embalmers' => '遗体防腐师',
        'emergency' => '急诊',
        'engravers' => '雕刻工',
        'engine' => '发动机',
        'english' => '英语',
        'environmental' => '环境',
        'equipment' => '设备',
        'erectors' => '搭建工',
        'escorts' => '陪同人员',
        'estate' => '地产',
        'etchers' => '蚀刻工',
        'examiners' => '审查员',
        'exercise' => '健身',
        'fabric' => '织物',
        'fabricators' => '制造工',
        'fallers' => '伐木工',
        'farm' => '农场',
        'farmworkers' => '农场工人',
        'fence' => '栅栏',
        'fiberglass' => '玻璃纤维',
        'file' => '档案',
        'filers' => '锉削工',
        'finishers' => '修整工',
        'fire' => '消防',
        'fish' => '鱼类',
        'fitness' => '健身',
        'flaggers' => '旗手',
        'floor' => '地板',
        'foresters' => '林务员',
        'forwarders' => '货代',
        'foundry' => '铸造',
        'freight' => '货运',
        'funeral' => '殡葬',
        'furniture' => '家具',
        'gambling' => '博彩',
        'game' => '野生动物',
        'garment' => '服装',
        'gas' => '天然气',
        'general' => '普通',
        'geneticists' => '遗传学家',
        'geodetic' => '大地测量',
        'glass' => '玻璃',
        'government' => '政府',
        'graders' => '分级员',
        'greenhouse' => '温室',
        'grinders' => '磨削工',
        'guards' => '警卫',
        'guides' => '导游',
        'gynecologists' => '妇科医师',
        'hand' => '手工',
        'handlers' => '处理工',
        'heavy' => '重型',
        'helpers' => '帮工',
        'histotechnologists' => '组织技术专家',
        'home' => '家庭',
        'hospitalists' => '住院医师',
        'hostesses' => '接待员',
        'hosts' => '接待员',
        'hotel' => '酒店',
        'immunologists' => '免疫学医师',
        'industrial' => '工业',
        'infantry' => '步兵',
        'inspectors' => '检查员',
        'installers' => '安装工',
        'instrument' => '仪器',
        'insurance' => '保险',
        'internal' => '内科',
        'interviewers' => '面谈员',
        'inventory' => '库存',
        'investigators' => '调查员',
        'janitors' => '清洁员',
        'judges' => '法官',
        'judicial' => '司法',
        'kindergarten' => '幼儿园',
        'labor' => '劳务',
        'laborers' => '劳工',
        'lamborghini' => '',
        'laminators' => '层压工',
        'landscape' => '景观',
        'language' => '语言',
        'layers' => '铺设工',
        'leaders' => '负责人',
        'legislators' => '立法者',
        'license' => '执照',
        'light' => '轻型',
        'line' => '一线',
        'lobby' => '大堂',
        'lock' => '船闸',
        'locksmiths' => '锁匠',
        'lounge' => '休息室',
        'lyricists' => '作词人',
        'machine' => '机器',
        'machinery' => '机械',
        'machinists' => '机械加工工',
        'magistrate' => '地方法官',
        'magistrates' => '地方法官',
        'mail' => '邮件',
        'maids' => '客房清洁员',
        'management' => '管理',
        'manufactured' => '预制',
        'marble' => '大理石',
        'marine' => '船舶',
        'masons' => '泥瓦工',
        'mates' => '大副',
        'material' => '物料',
        'materials' => '材料',
        'mathematicians' => '数学家',
        'mathematical' => '数学',
        'measurers' => '测量员',
        'meat' => '肉类',
        'mechanics' => '机械师',
        'medicine' => '医学',
        'members' => '成员',
        'merchants' => '商户',
        'metal' => '金属',
        'midwives' => '助产士',
        'military' => '军事',
        'millwrights' => '机械安装工',
        'missile' => '导弹',
        'mobile' => '移动',
        'model' => '模型',
        'molders' => '成型工',
        'molecular' => '分子',
        'monitors' => '监督员',
        'morticians' => '殡葬师',
        'motel' => '汽车旅馆',
        'motor' => '电机',
        'motorcycle' => '摩托车',
        'movers' => '搬运工',
        'municipal' => '市政',
        'musical' => '音乐',
        'nannies' => '保姆',
        'naturopathic' => '自然疗法',
        'naval' => '船舶',
        'neurologists' => '神经科医师',
        'neuropsychologists' => '神经心理学家',
        'nursery' => '苗圃',
        'obstetricians' => '产科医师',
        'occupational' => '作业',
        'occupations' => '职业',
        'offbearers' => '下料工',
        'office' => '办公室',
        'oil' => '石油',
        'oilers' => '机油员',
        'online' => '在线',
        'ophthalmologists' => '眼科医师',
        'opticians' => '验光配镜师',
        'order' => '订单',
        'orderlies' => '护工',
        'organizational' => '组织',
        'orthodontists' => '正畸医师',
        'orthoptists' => '视轴矫正师',
        'outdoor' => '户外',
        'packagers' => '包装员',
        'packers' => '包装工',
        'painters' => '油漆工',
        'paperhangers' => '裱糊工',
        'paramedics' => '急救医护人员',
        'park' => '公园',
        'parking' => '停车',
        'passenger' => '乘客',
        'pathologists' => '病理医师',
        'patient' => '患者',
        'patternmakers' => '制版工',
        'payroll' => '薪资',
        'pavers' => '铺路工',
        'pediatricians' => '儿科医师',
        'pediatric' => '儿科',
        'penetration' => '渗透',
        'pesticide' => '农药',
        'physicians' => '医师',
        'physicists' => '物理学家',
        'pipelayers' => '管道铺设工',
        'pipefitters' => '管道装配工',
        'planners' => '规划师',
        'plastic' => '塑料',
        'plasterers' => '抹灰工',
        'plumbers' => '管道工',
        'poets' => '诗人',
        'police' => '警察',
        'posting' => '过账',
        'postmasters' => '邮政局长',
        'postsecondary' => '高等教育',
        'power' => '电力',
        'preschool' => '学前',
        'pressers' => '熨烫工',
        'preventive' => '预防',
        'private' => '私人',
        'procurement' => '采购',
        'processors' => '处理员',
        'products' => '产品',
        'programs' => '项目',
        'promoters' => '推广员',
        'projectionists' => '放映员',
        'property' => '财产',
        'prosthodontists' => '修复牙科医师',
        'psychiatric' => '精神科',
        'psychiatrists' => '精神科医师',
        'psychologists' => '心理学家',
        'purchasing' => '采购',
        'pumpers' => '泵操作员',
        'quarry' => '采石场',
        'rail' => '铁路',
        'railroad' => '铁路',
        'ranch' => '牧场',
        'readers' => '读表员',
        'receiving' => '收货',
        'recordkeeping' => '记录',
        'recreation' => '休闲',
        'recyclable' => '可回收',
        'recycling' => '回收',
        'relay' => '继电器',
        'rental' => '租赁',
        'representatives' => '代表',
        'resort' => '度假村',
        'restoration' => '修复',
        'retail' => '零售',
        'ranch' => '牧场',
        'riggers' => '索具工',
        'rigging' => '索具',
        'roofers' => '屋顶工',
        'room' => '房间',
        'roustabouts' => '杂务工',
        'runners' => '跑腿员',
        'safe' => '保险柜',
        'sailors' => '水手',
        'samplers' => '采样员',
        'sanders' => '打磨工',
        'scalers' => '检尺员',
        'science' => '科学',
        'searchers' => '检索员',
        'secondary' => '中等教育',
        'segmental' => '块状',
        'septic' => '化粪池',
        'servicers' => '维修员',
        'setters' => '铺设工',
        'sewers' => '缝纫工',
        'shampooers' => '洗发师',
        'shapers' => '塑形工',
        'sharpeners' => '刃磨工',
        'shipping' => '发运',
        'short' => '简餐',
        'shuttle' => '穿梭车',
        'signal' => '信号',
        'slaughterers' => '屠宰工',
        'small' => '小型',
        'sorters' => '分拣员',
        'sprayers' => '喷洒工',
        'statisticians' => '统计师',
        'steamfitters' => '蒸汽管道工',
        'stockers' => '补货员',
        'stone' => '石材',
        'stonemasons' => '石匠',
        'stucco' => '灰泥',
        'substation' => '变电站',
        'substance' => '物质',
        'superintendents' => '主管',
        'supervisors' => '主管',
        'surfaces' => '表面',
        'surveyors' => '测量员',
        'systems' => '系统',
        'tailors' => '裁缝',
        'tank' => '罐体',
        'tapers' => '包扎工',
        'tactical' => '战术',
        'tax' => '税务',
        'taxi' => '出租车',
        'team' => '团队',
        'telemarketers' => '电话销售员',
        'tenders' => '看护员',
        'testers' => '测试员',
        'textile' => '纺织',
        'therapy' => '治疗',
        'ticket' => '票务',
        'tile' => '瓷砖',
        'timekeeping' => '考勤',
        'timing' => '计时',
        'tire' => '轮胎',
        'title' => '产权',
        'tool' => '工具',
        'tour' => '导游',
        'track' => '轨道',
        'trainers' => '训练师',
        'transit' => '公交',
        'travel' => '旅行',
        'treasurers' => '司库',
        'truck' => '卡车',
        'typists' => '打字员',
        'undertakers' => '殡仪承办人',
        'upholsterers' => '家具软包工',
        'urologists' => '泌尿科医师',
        'ushers' => '引座员',
        'utilities' => '公用事业',
        'valve' => '阀门',
        'vegetation' => '植被',
        'vending' => '自动售货',
        'vessels' => '船舶',
        'wardens' => '管理员',
        'watch' => '钟表',
        'watercraft' => '船艇',
        'weighers' => '称重员',
        'wellhead' => '井口',
        'wholesale' => '批发',
        'winders' => '绕线工',
        'wood' => '木材',
        'woodworkers' => '木工',
        'writers' => '写作者',
    ];

    /**
     * @param  array<string, mixed>  $record
     * @return array{canonical_slug:string,title_en:string,title_zh:string}
     */
    public function familyPayload(array $record): array
    {
        $slug = $this->familySlug($record);
        $label = self::FAMILY_LABELS[$slug] ?? self::FAMILY_LABELS['__unknown__'];

        return [
            'canonical_slug' => $slug,
            'title_en' => $label['en'],
            'title_zh' => $label['zh'],
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function familySlug(array $record): string
    {
        $market = strtoupper(trim((string) ($record['market'] ?? '')));
        $title = $this->titleZh($record).' '.$this->titleEn($record).' '.(string) data_get($record, 'taxonomy.major_group_title_en').' '.(string) data_get($record, 'taxonomy.minor_group_title_en');

        if ($market === 'US') {
            return $this->usFamilySlug($record, $title);
        }

        if ($market === 'CN') {
            return $this->cnFamilySlug($record, $title);
        }

        return '__unknown__';
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function titleZh(array $record): string
    {
        $market = strtoupper(trim((string) ($record['market'] ?? '')));
        $title = trim((string) data_get($record, 'identity.canonical_title_zh'));
        if ($title !== '' && ($market !== 'US' || ! $this->isWeakChineseTitle($title))) {
            return $title;
        }

        $fallback = trim((string) data_get($record, 'identity.source_title_zh'));
        if ($fallback !== '' && ($market !== 'US' || ! $this->isWeakChineseTitle($fallback))) {
            return $fallback;
        }

        $sourceEn = trim((string) data_get($record, 'identity.source_title_en'));
        if ($market === 'US' && $sourceEn !== '') {
            return $this->translateEnglishTitleToChinese($sourceEn);
        }

        return $sourceEn !== '' ? $sourceEn : $this->titleEn($record);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function titleEn(array $record): string
    {
        $market = strtoupper(trim((string) ($record['market'] ?? '')));
        $sourceEn = trim((string) data_get($record, 'identity.source_title_en'));
        $canonicalEn = trim((string) data_get($record, 'identity.canonical_title_en'));

        if ($market === 'US') {
            return $sourceEn !== '' ? Str::headline($sourceEn) : ($canonicalEn !== '' ? Str::headline($canonicalEn) : (string) data_get($record, 'identity.proposed_slug'));
        }

        $titleZh = $this->titleZh($record);
        if ($titleZh !== '') {
            return $this->translateChineseTitle($titleZh);
        }

        return $canonicalEn !== '' ? $this->cleanMachineEnglish($canonicalEn) : (string) data_get($record, 'identity.proposed_slug');
    }

    public function translateChineseTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            return '';
        }

        if (isset(self::EXACT_CN_TRANSLATIONS[$title])) {
            return self::EXACT_CN_TRANSLATIONS[$title];
        }

        foreach ([
            '工程技术人员' => 'Engineering Technicians',
            '专业技术人员' => 'Professional Technicians',
            '技术人员' => 'Technicians',
            '工程师' => 'Engineers',
            '设计师' => 'Designers',
            '设计员' => 'Designers',
            '测试员' => 'Testers',
            '检测员' => 'Inspectors and Testers',
            '检验员' => 'Inspectors',
            '维修工' => 'Repairers',
            '维修人员' => 'Maintenance and Repair Workers',
            '安装工' => 'Installers',
            '操作工' => 'Operators',
            '装配工' => 'Assemblers',
            '制造工' => 'Manufacturing Workers',
            '加工工' => 'Processing Workers',
            '负责人' => 'Managers',
            '管理员' => 'Administrators',
            '管理师' => 'Management Specialists',
            '分析师' => 'Analysts',
            '咨询师' => 'Consultants',
            '治疗师' => 'Therapists',
            '医师' => 'Physicians',
            '护士' => 'Nurses',
            '教师' => 'Teachers',
            '教练' => 'Coaches',
            '研究员' => 'Researchers',
            '记者' => 'Reporters',
            '编辑' => 'Editors',
            '会计师' => 'Accountants',
            '审计师' => 'Auditors',
            '经济师' => 'Economists',
            '统计师' => 'Statisticians',
            '消防员' => 'Firefighters',
            '保安员' => 'Security Guards',
            '销售员' => 'Sales Workers',
            '服务员' => 'Service Workers',
            '驾驶员' => 'Drivers',
            '飞行员' => 'Pilots',
            '工' => 'Workers',
            '员' => 'Workers',
            '师' => 'Specialists',
        ] as $suffix => $englishSuffix) {
            if (Str::endsWith($title, $suffix)) {
                $base = Str::beforeLast($title, $suffix);
                $translatedBase = $this->translateChineseBase($base);

                return $this->cleanEnglish(trim($translatedBase.' '.$englishSuffix));
            }
        }

        return $this->cleanEnglish($this->translateChineseBase($title).' Workers');
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function usFamilySlug(array $record, string $title): string
    {
        $normalized = strtolower($title);
        if (str_contains($normalized, 'media') || str_contains($normalized, 'communication') || str_contains($normalized, 'public relations') || str_contains($normalized, 'writers') || str_contains($normalized, 'news')) {
            return 'media-and-communication';
        }
        if (str_contains($normalized, 'broadcast') || str_contains($normalized, 'audio') || str_contains($normalized, 'video') || str_contains($normalized, 'camera') || str_contains($normalized, 'film') || str_contains($normalized, 'sound') || str_contains($normalized, 'lighting')) {
            return 'media-and-communication';
        }
        if (str_contains($normalized, 'sports') || str_contains($normalized, 'athlete') || str_contains($normalized, 'entertainment') || str_contains($normalized, 'dancer') || str_contains($normalized, 'choreographer') || str_contains($normalized, 'disc jockey') || str_contains($normalized, 'talent director')) {
            return 'entertainment-and-sports';
        }
        if (str_contains($normalized, 'design') || str_contains($normalized, 'artist') || str_contains($normalized, 'fashion') || str_contains($normalized, 'displayers') || str_contains($normalized, 'window trimmers')) {
            return 'arts-and-design';
        }
        if (str_contains($normalized, 'mathematic') || str_contains($normalized, 'statistic') || str_contains($normalized, 'actuar')) {
            return 'math';
        }

        $majorGroupCode = trim((string) data_get($record, 'taxonomy.major_group_code'));
        $major = $majorGroupCode !== '' ? substr($majorGroupCode, 0, 2) : substr(trim((string) data_get($record, 'authority.code')), 0, 2);

        return self::US_MAJOR_FAMILY[$major] ?? '__unknown__';
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function cnFamilySlug(array $record, string $title): string
    {
        $normalized = strtolower($title);
        foreach ([
            'computer-and-information-technology' => ['计算机', '软件', '网络', '信息', '数据', '互联网', '人工智能', '区块链', '通信', 'program', 'software', 'network', 'data'],
            'healthcare' => ['医疗', '医学', '医师', '护士', '护理', '药剂', '药房', '康复', '卫生', 'health', 'medical', 'pharmacy'],
            'legal' => ['法律', '律师', '法官', '检察', '司法', '公证', 'legal', 'court'],
            'education-training-and-library' => ['教育', '教师', '教学', '培训', '图书', '档案', 'teacher', 'education'],
            'media-and-communication' => ['新闻', '媒体', '编辑', '出版', '播音', '主持', '广告', '传播', '记者', 'media'],
            'arts-and-design' => ['艺术', '美术', '设计', '工艺', '服装', '设计师', 'artist', 'design'],
            'entertainment-and-sports' => ['演员', '演出', '影视', '体育', '运动', '健身', '旅游', '讲解', 'entertainment', 'sports'],
            'business-and-financial' => ['金融', '银行', '证券', '保险', '会计', '审计', '财务', '税务', '经济', '商务', 'business', 'financial'],
            'architecture-and-engineering' => ['工程技术', '工程师', '建筑设计', '土木', '测绘', '计量工程', 'engineering'],
            'construction-and-extraction' => ['施工', '建筑工', '采矿', '采掘', '爆破', '矿', 'construction', 'mining'],
            'installation-maintenance-and-repair' => ['维修', '维护', '安装', '修理', 'repair', 'maintenance'],
            'transportation-and-material-moving' => ['运输', '驾驶', '司机', '船员', '飞行', '铁路', '航空', '物流', '仓储', 'transport'],
            'food-preparation-and-serving' => ['餐饮', '烹饪', '厨师', '食品', '茶艺', '调酒', 'food'],
            'building-and-grounds-cleaning' => ['清洁', '保洁', '物业', '绿化', '害虫', 'pest', 'cleaning'],
            'protective-service' => ['安全', '保安', '消防', '警', '应急', '救援', 'protective', 'security'],
            'sales' => ['销售', '营销', '推销', '采购', '市场', 'sales', 'marketing'],
            'community-and-social-service' => ['社会工作', '社区', '心理咨询', '婚姻', '殡葬', 'social'],
            'life-physical-and-social-science' => ['科研', '研究', '物理', '化学', '生物', '地质', '气象', '环境', 'science'],
            'math' => ['数学', '统计', '精算', '计量员', 'measurement', 'statistics'],
            'personal-care-and-service' => ['美容', '美发', '护理员', '家政', '养老', '育婴', 'personal care'],
        ] as $slug => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($normalized, strtolower($needle))) {
                    return $slug;
                }
            }
        }

        $majorCode = trim((string) data_get($record, 'taxonomy.major_code'));
        if ($majorCode === '') {
            $authorityCode = trim((string) data_get($record, 'authority.code'));
            $majorCode = substr($authorityCode, 0, 1);
        }

        return self::CN_MAJOR_FAMILY[$majorCode] ?? match ($majorCode) {
            '2' => 'life-physical-and-social-science',
            '4' => 'personal-care-and-service',
            default => '__unknown__',
        };
    }

    private function translateChineseBase(string $base): string
    {
        $remaining = trim($base);
        if ($remaining === '') {
            return '';
        }

        $translated = [];
        $terms = self::CN_TERM_TRANSLATIONS;
        uksort($terms, static fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));

        while ($remaining !== '') {
            $matched = false;
            foreach ($terms as $term => $english) {
                if (str_starts_with($remaining, $term)) {
                    $translated[] = $english;
                    $remaining = mb_substr($remaining, mb_strlen($term));
                    $matched = true;
                    break;
                }
            }

            if ($matched) {
                continue;
            }

            $remaining = mb_substr($remaining, 1);
        }

        return $translated === [] ? 'Occupational' : implode(' ', $translated);
    }

    private function cleanMachineEnglish(string $title): string
    {
        if (preg_match('/\p{Han}/u', $title) === 1) {
            return $this->translateChineseTitle(preg_replace('/\s+/u', '', $title) ?? $title);
        }

        return $this->cleanEnglish($title);
    }

    private function translateEnglishTitleToChinese(string $title): string
    {
        $clean = $this->cleanEnglish($title);
        if (isset(self::EXACT_US_ZH_TRANSLATIONS[$clean])) {
            return self::EXACT_US_ZH_TRANSLATIONS[$clean];
        }

        $segments = preg_split('/\s*,\s*/', strtolower($clean)) ?: [];
        $translated = [];
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            $segmentZh = $this->translateEnglishSegmentToChinese($segment);
            if ($segmentZh !== '') {
                $translated[] = $segmentZh;
            }
        }

        $result = implode('、', array_values(array_unique($translated)));
        $result = str_replace(['和和', '其他其他', '人员员', '工工'], ['和', '其他', '人员', '工'], $result);

        return $result !== '' ? $result : '其他职业';
    }

    private function translateEnglishSegmentToChinese(string $segment): string
    {
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', strtolower($segment)) ?? '';
        $words = array_values(array_filter(explode(' ', trim($normalized)), static fn (string $word): bool => $word !== ''));
        if ($words === []) {
            return '';
        }

        $translated = [];
        for ($index = 0; $index < count($words);) {
            $matched = false;
            $maxLength = min(7, count($words) - $index);
            for ($length = $maxLength; $length >= 1; $length--) {
                $phrase = implode(' ', array_slice($words, $index, $length));
                if (isset(self::EN_PHRASE_ZH_TRANSLATIONS[$phrase])) {
                    $translated[] = self::EN_PHRASE_ZH_TRANSLATIONS[$phrase];
                    $index += $length;
                    $matched = true;
                    break;
                }
            }

            if ($matched) {
                continue;
            }

            $word = $words[$index];
            if (in_array($word, ['a', 'an', 'as', 'at', 'by', 'except', 'for', 'from', 'in', 'of', 'or', 'the', 'through', 'to', 'with'], true)) {
                $index++;

                continue;
            }

            if ($word === 'all' && ($words[$index + 1] ?? '') === 'other') {
                $translated[] = '其他';
                $index += 2;

                continue;
            }

            if ($word === 'other') {
                $translated[] = '其他';
                $index++;

                continue;
            }

            if (isset(self::EN_TERM_ZH_TRANSLATIONS[$word])) {
                $translated[] = self::EN_TERM_ZH_TRANSLATIONS[$word];
            }

            $index++;
        }

        return implode('', array_values(array_filter($translated, static fn (?string $part): bool => $part !== null && $part !== '')));
    }

    private function isWeakChineseTitle(string $title): bool
    {
        $normalized = trim($title);
        if ($normalized === '') {
            return true;
        }

        return in_array($normalized, ['技术员', '操作员', '经理', '工人', '工程', '专员', '人员'], true)
            || mb_strlen($normalized) <= 2;
    }

    private function cleanEnglish(string $title): string
    {
        $title = preg_replace('/\s+/u', ' ', trim($title)) ?? trim($title);
        $title = str_replace('  ', ' ', $title);

        return Str::headline($title);
    }
}
