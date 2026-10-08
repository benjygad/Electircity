import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../models/models.dart';
import '../widgets/common.dart';

class SupportTab extends StatefulWidget {
  const SupportTab({super.key});
  @override
  State<SupportTab> createState() => _SupportTabState();
}

class _SupportTabState extends State<SupportTab> {
  List<FaultReport> _reports = [];
  List<Meter> _meters = [];
  String? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    try {
      final api = SessionStore.api;
      final list = await api.get('/fault-reports') as List;
      _reports = list.map((e) => FaultReport.fromJson(Map<String, dynamic>.from(e))).toList();
      final meters = await api.get('/meters') as List;
      _meters = meters.map((e) => Meter.fromJson(Map<String, dynamic>.from(e))).toList();
    } on ApiException catch (e) {
      _error = e.message;
    } catch (_) {
      _error = 'Could not reach the SEMS server.';
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _newReport() async {
    final created = await Navigator.push<bool>(context, MaterialPageRoute(builder: (_) => NewReportScreen(meters: _meters)));
    if (created == true) _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Support & fault reports')),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: kTeal, foregroundColor: Colors.white,
        onPressed: _newReport, icon: const Icon(Icons.report_problem_outlined), label: const Text('Report a fault'),
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: _loading && _reports.isEmpty
            ? const Center(child: CircularProgressIndicator())
            : _error != null && _reports.isEmpty
                ? ListView(children: [ErrorBox(_error!, onRetry: _load)])
                : _reports.isEmpty
                    ? ListView(children: const [
                        SimBanner(),
                        SizedBox(height: 50),
                        EmptyState(Icons.support_agent, 'No fault reports yet.\nReport power outages, meter problems or supply issues here.'),
                      ])
                    : ListView(padding: const EdgeInsets.fromLTRB(16, 12, 16, 90), physics: const AlwaysScrollableScrollPhysics(), children: [
                        for (final r in _reports) Card(
                          margin: const EdgeInsets.only(bottom: 10),
                          child: InkWell(
                            borderRadius: BorderRadius.circular(16),
                            onTap: () async {
                              await Navigator.push(context, MaterialPageRoute(builder: (_) => ReportDetailScreen(reportId: r.id)));
                              _load();
                            },
                            child: Padding(
                              padding: const EdgeInsets.all(14),
                              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                Row(children: [
                                  Expanded(child: Text(r.categoryLabel, style: const TextStyle(fontWeight: FontWeight.w800))),
                                  StatusChip(r.status),
                                ]),
                                const SizedBox(height: 6),
                                Text(r.description, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 13, color: Color(0xFF33415C))),
                                const SizedBox(height: 8),
                                Row(children: [
                                  const Icon(Icons.schedule, size: 13, color: kMuted),
                                  const SizedBox(width: 4),
                                  Text(dmyTime(r.createdAt), style: const TextStyle(fontSize: 11, color: kMuted)),
                                  if (r.meterNumber != null) ...[
                                    const SizedBox(width: 14),
                                    const Icon(Icons.electric_meter, size: 13, color: kMuted),
                                    const SizedBox(width: 4),
                                    Text(r.meterNumber!, style: const TextStyle(fontSize: 11, color: kMuted)),
                                  ],
                                ]),
                              ]),
                            ),
                          ),
                        ),
                      ]),
      ),
    );
  }
}

class NewReportScreen extends StatefulWidget {
  const NewReportScreen({super.key, required this.meters});
  final List<Meter> meters;
  @override
  State<NewReportScreen> createState() => _NewReportScreenState();
}

class _NewReportScreenState extends State<NewReportScreen> {
  static const categories = [
    ['power_outage', 'Power outage'],
    ['meter_problem', 'Meter problem'],
    ['suspected_incorrect_reading', 'Suspected incorrect reading'],
    ['supply_issue', 'Electricity supply issue'],
    ['other', 'Other'],
  ];

  String _category = 'power_outage';
  Meter? _meter;
  final _description = TextEditingController();
  bool _busy = false;
  String? _error;

  Future<void> _submit() async {
    if (_description.text.trim().length < 10) {
      setState(() => _error = 'Please describe the problem in a little more detail (at least 10 characters).');
      return;
    }
    setState(() { _busy = true; _error = null; });
    try {
      await SessionStore.api.post('/fault-reports', {
        'category': _category,
        'description': _description.text.trim(),
        if (_meter != null) 'meter_id': _meter!.id,
      });
      if (mounted) Navigator.pop(context, true);
    } on ApiException catch (e) {
      setState(() { _error = e.detailed; _busy = false; });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Report a fault')),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        const Text('Category', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
        const SizedBox(height: 8),
        Wrap(spacing: 8, runSpacing: 8, children: [
          for (final c in categories) ChoiceChip(
            label: Text(c[1]),
            selected: _category == c[0],
            onSelected: (_) => setState(() => _category = c[0]),
          ),
        ]),
        const SizedBox(height: 14),
        if (widget.meters.isNotEmpty) ...[
          DropdownButtonFormField<Meter>(
            value: _meter,
            decoration: const InputDecoration(labelText: 'Related meter (optional)'),
            items: [
              const DropdownMenuItem<Meter>(value: null, child: Text('Not meter-specific')),
              ...widget.meters.map((m) => DropdownMenuItem<Meter>(value: m, child: Text('${m.meterNumber} — ${m.serviceLocation}'))),
            ],
            onChanged: (m) => setState(() => _meter = m),
          ),
          const SizedBox(height: 14),
        ],
        TextField(
          controller: _description,
          maxLines: 5,
          decoration: const InputDecoration(labelText: 'Describe the problem', alignLabelWithHint: true),
        ),
        if (_error != null) Padding(padding: const EdgeInsets.only(top: 10), child: Text(_error!, style: const TextStyle(color: kRed, fontSize: 12.5))),
        const SizedBox(height: 18),
        ElevatedButton(
          onPressed: _busy ? null : _submit,
          child: _busy
              ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white))
              : const Text('Submit report'),
        ),
      ]),
    );
  }
}

class ReportDetailScreen extends StatefulWidget {
  const ReportDetailScreen({super.key, required this.reportId});
  final int reportId;
  @override
  State<ReportDetailScreen> createState() => _ReportDetailScreenState();
}

class _ReportDetailScreenState extends State<ReportDetailScreen> {
  Map<String, dynamic>? _report;
  List<SupportMessage> _messages = [];
  final _input = TextEditingController();
  String? _error;
  bool _sending = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final api = SessionStore.api;
      final detail = await api.get('/fault-reports/${widget.reportId}');
      final msgs = await api.get('/fault-reports/${widget.reportId}/messages') as List;
      setState(() {
        _report = Map<String, dynamic>.from(detail);
        _messages = msgs.map((e) => SupportMessage.fromJson(Map<String, dynamic>.from(e))).toList();
        _error = null;
      });
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    }
  }

  Future<void> _send() async {
    final text = _input.text.trim();
    if (text.isEmpty) return;
    setState(() => _sending = true);
    try {
      await SessionStore.api.post('/fault-reports/${widget.reportId}/messages', {'message': text});
      _input.clear();
      await _load();
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = context.read<SessionStore>();
    final myId = session.user?['id'];
    return Scaffold(
      appBar: AppBar(title: Text('Report #${widget.reportId}')),
      body: _error != null && _report == null
          ? ErrorBox(_error!, onRetry: _load)
          : _report == null
              ? const Center(child: CircularProgressIndicator())
              : Column(children: [
                  Container(
                    width: double.infinity, color: Colors.white,
                    padding: const EdgeInsets.all(14),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Row(children: [
                        Expanded(child: Text(
                          (const {'power_outage': 'Power outage', 'meter_problem': 'Meter problem', 'suspected_incorrect_reading': 'Suspected incorrect reading', 'supply_issue': 'Supply issue', 'other': 'Other'})[_report!['category']] ?? 'Report',
                          style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15))),
                        StatusChip('${_report!['status']}'),
                      ]),
                      const SizedBox(height: 6),
                      Text(_report!['description'], style: const TextStyle(fontSize: 13, color: Color(0xFF33415C))),
                      const SizedBox(height: 6),
                      Text('Submitted ${dmyTime(DateTime.tryParse('${_report!['created_at']}') ?? DateTime.now())}', style: const TextStyle(fontSize: 11, color: kMuted)),
                    ]),
                  ),
                  const Divider(height: 1),
                  Expanded(
                    child: _messages.isEmpty
                        ? const EmptyState(Icons.chat_bubble_outline, 'No messages yet. Support will reply here.')
                        : ListView.builder(
                            padding: const EdgeInsets.all(14),
                            itemCount: _messages.length,
                            itemBuilder: (context, i) {
                              final m = _messages[i];
                              final mine = m.senderId == myId;
                              return Align(
                                alignment: mine ? Alignment.centerRight : Alignment.centerLeft,
                                child: Container(
                                  margin: const EdgeInsets.only(bottom: 10),
                                  padding: const EdgeInsets.symmetric(horizontal: 13, vertical: 9),
                                  constraints: BoxConstraints(maxWidth: MediaQuery.of(context).size.width * .78),
                                  decoration: BoxDecoration(
                                    color: mine ? kNavy : const Color(0xFFEEF1F5),
                                    borderRadius: BorderRadius.circular(13).copyWith(bottomRight: mine ? const Radius.circular(4) : null, bottomLeft: mine ? null : const Radius.circular(4)),
                                  ),
                                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                    Text('${m.senderName} · ${dmyTime(m.createdAt)}', style: TextStyle(fontSize: 10, fontWeight: FontWeight.w700, color: mine ? const Color(0xFF9FB4D8) : kMuted)),
                                    const SizedBox(height: 3),
                                    Text(m.message, style: TextStyle(fontSize: 13, color: mine ? Colors.white : const Color(0xFF16213A))),
                                  ]),
                                ),
                              );
                            },
                          ),
                  ),
                  SafeArea(
                    top: false,
                    child: Container(
                      color: Colors.white,
                      padding: const EdgeInsets.fromLTRB(12, 8, 12, 10),
                      child: Row(children: [
                        Expanded(child: TextField(controller: _input, decoration: const InputDecoration(hintText: 'Write a message…'), onSubmitted: (_) => _send())),
                        const SizedBox(width: 8),
                        IconButton.filled(
                          backgroundColor: kTeal,
                          onPressed: _sending ? null : _send,
                          icon: _sending ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : const Icon(Icons.send, size: 18),
                        ),
                      ]),
                    ),
                  ),
                ]),
    );
  }
}
