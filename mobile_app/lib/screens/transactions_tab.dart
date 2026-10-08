import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../models/models.dart';
import '../widgets/common.dart';

class TransactionsTab extends StatefulWidget {
  const TransactionsTab({super.key});
  @override
  State<TransactionsTab> createState() => _TransactionsTabState();
}

class _TransactionsTabState extends State<TransactionsTab> {
  List<Meter> _meters = [];
  Meter? _selected;
  List<TokenTransaction> _txs = [];
  String _statusFilter = '';
  String? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _init();
  }

  Future<void> _init() async {
    try {
      final list = await SessionStore.api.get('/meters') as List;
      _meters = list.map((e) => Meter.fromJson(Map<String, dynamic>.from(e))).toList();
      if (_meters.isNotEmpty) {
        _selected = _meters.first;
        await _load();
      } else {
        setState(() => _loading = false);
      }
    } catch (_) {
      setState(() { _loading = false; _error = 'Could not load meters.'; });
    }
  }

  Future<void> _load() async {
    if (_selected == null) return;
    setState(() { _loading = true; _error = null; });
    try {
      final params = <String>['limit=50'];
      if (_statusFilter.isNotEmpty) params.add('status=$_statusFilter');
      final list = await SessionStore.api.get('/meters/${_selected!.id}/transactions?${params.join('&')}') as List;
      setState(() {
        _txs = list.map((e) => TokenTransaction.fromJson(Map<String, dynamic>.from(e))).toList();
        _loading = false;
      });
    } on ApiException catch (e) {
      setState(() { _error = e.message; _loading = false; });
    } catch (_) {
      setState(() { _error = 'Could not reach the SEMS server.'; _loading = false; });
    }
  }

  void _recordPurchaseSheet() {
    final amount = TextEditingController();
    final token = TextEditingController();
    String? dialogError;
    if (_selected == null) return;

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheet) => Padding(
          padding: EdgeInsets.fromLTRB(20, 18, 20, MediaQuery.of(ctx).viewInsets.bottom + 20),
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Row(children: [
              Container(width: 38, height: 38, decoration: BoxDecoration(color: kTeal.withOpacity(.15), borderRadius: BorderRadius.circular(10)),
                child: const Icon(Icons.receipt_long, color: kTeal, size: 20)),
              const SizedBox(width: 10),
              const Expanded(child: Text('Record token purchase', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 16))),
            ]),
            const SizedBox(height: 10),
            const Text(
              'This records a SIMULATED purchase in SEMS for tracking only. No token is sent to a physical LUKU meter — no authorized utility integration exists.',
              style: TextStyle(fontSize: 11.5, color: Color(0xFF0B6470)),
            ),
            const SizedBox(height: 12),
            if (dialogError != null)
              Padding(padding: const EdgeInsets.only(bottom: 10), child: Text(dialogError!, style: const TextStyle(color: kRed, fontSize: 12.5))),
            TextField(controller: amount, keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(labelText: 'Amount (TZS)', prefixIcon: Icon(Icons.payments_outlined))),
            const SizedBox(height: 10),
            TextField(controller: token, keyboardType: TextInputType.number, maxLength: 24,
              decoration: const InputDecoration(labelText: 'Token (optional, 20 digits)', prefixIcon: Icon(Icons.key_outlined),
                helperText: 'Only the last 4 digits are stored.')),
            const SizedBox(height: 14),
            ElevatedButton(
              onPressed: () async {
                setSheet(() => dialogError = null);
                try {
                  final body = <String, dynamic>{'amount': double.parse(amount.text.trim())};
                  if (token.text.trim().isNotEmpty) body['token'] = token.text.trim();
                  await SessionStore.api.post('/meters/${_selected!.id}/transactions', body);
                  if (ctx.mounted) Navigator.pop(ctx);
                  _load();
                  if (context.mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
                      content: Text('Simulated purchase recorded. It was NOT loaded onto a physical meter.'),
                    ));
                  }
                } on ApiException catch (e) {
                  setSheet(() => dialogError = e.detailed);
                } on FormatException {
                  setSheet(() => dialogError = 'Enter a valid amount.');
                }
              },
              child: const Text('Record purchase'),
            ),
          ]),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Token transactions'),
        actions: [if (_selected != null) IconButton(icon: const Icon(Icons.add_circle_outline), onPressed: _recordPurchaseSheet)],
      ),
      floatingActionButton: _selected == null ? null : FloatingActionButton.extended(
        backgroundColor: kTeal, foregroundColor: Colors.white,
        onPressed: _recordPurchaseSheet,
        icon: const Icon(Icons.add), label: const Text('Record purchase'),
      ),
      body: _meters.isEmpty && !_loading
          ? ListView(children: const [SimBanner(), SizedBox(height: 40), EmptyState(Icons.receipt_long, 'No meter linked to your account yet.')])
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 4, 16, 90),
                physics: const AlwaysScrollableScrollPhysics(),
                children: [
                  const SimBanner(text: 'All token operations in this prototype are SIMULATED records used for expenditure tracking.'),
                  const SizedBox(height: 10),
                  if (_meters.length > 1) ...[
                    DropdownButtonFormField<Meter>(
                      value: _selected,
                      decoration: const InputDecoration(labelText: 'Meter'),
                      items: _meters.map((m) => DropdownMenuItem(value: m, child: Text(m.meterNumber))).toList(),
                      onChanged: (m) { setState(() => _selected = m); _load(); },
                    ),
                    const SizedBox(height: 10),
                  ],
                  Wrap(spacing: 8, children: [
                    for (final f in const [['', 'All'], ['recorded', 'Recorded'], ['pending', 'Pending'], ['failed', 'Failed']])
                      ChoiceChip(
                        label: Text(f[1]),
                        selected: _statusFilter == f[0],
                        onSelected: (_) { setState(() => _statusFilter = f[0]); _load(); },
                      ),
                  ]),
                  const SizedBox(height: 10),
                  if (_error != null) ErrorBox(_error!, onRetry: _load),
                  if (_loading) const Padding(padding: EdgeInsets.all(40), child: Center(child: CircularProgressIndicator())),
                  if (!_loading && _txs.isEmpty) const EmptyState(Icons.receipt_long, 'No transactions match this filter.'),
                  for (final t in _txs) Card(
                    margin: const EdgeInsets.only(bottom: 8),
                    child: ListTile(
                      leading: CircleAvatar(backgroundColor: kGreen.withOpacity(.12), child: const Icon(Icons.bolt, color: kGreen, size: 20)),
                      title: Text(tzs(t.amount), style: const TextStyle(fontWeight: FontWeight.w800)),
                      subtitle: Text('${dmyTime(t.createdAt)}${t.tokenReference != null ? ' · ref ${t.tokenReference}' : ''}', style: const TextStyle(fontSize: 11.5)),
                      trailing: StatusChip(t.status),
                    ),
                  ),
                ],
              ),
            ),
    );
  }
}
