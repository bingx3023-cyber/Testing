import sqlite3
import random
import string
import asyncio
import requests
from telegram import Update, InlineKeyboardButton, InlineKeyboardMarkup
from telegram.ext import Application, CommandHandler, CallbackQueryHandler, MessageHandler, filters, ContextTypes

# API Key
TOKEN = '7656448308:AAEPkCNpBtiiw70r-pKuFPdWo6StZnBTeEE'

# Database setup
def init_db():
    conn = sqlite3.connect('bot.db')
    c = conn.cursor()
    c.execute('''CREATE TABLE IF NOT EXISTS users (
                    user_id INTEGER PRIMARY KEY,
                    banned INTEGER DEFAULT 0
                )''')
    c.execute('''CREATE TABLE IF NOT EXISTS orders (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER,
                    volume TEXT,
                    duration TEXT,
                    tracking_code TEXT,
                    payment_status TEXT DEFAULT 'pending',
                    config TEXT,
                    screenshot TEXT
                )''')
    conn.commit()
    conn.close()

init_db()

# Generate tracking code
def generate_tracking_code():
    return ''.join(random.choices(string.ascii_uppercase + string.digits, k=10))

# Sample config
SAMPLE_CONFIG = '''{
  "v": "2",
  "ps": "Sample Config",
  "add": "example.com",
  "port": "443",
  "id": "uuid-here",
  "aid": "0",
  "net": "ws",
  "type": "none",
  "host": "",
  "path": "/path",
  "tls": "tls"
}'''

async def get_bot_info(application):
    bot = application.bot
    bot_info = await bot.get_me()
    print(f"ایدی ربات: @{bot_info.username}")

# Admin password
ADMIN_PASSWORD = '1390'
ADMIN_USER_ID = None  # Will be set when admin logs in

# Main menu
def main_menu():
    keyboard = [
        [InlineKeyboardButton("📊 حجم کانفیگ", callback_data='volume')],
        [InlineKeyboardButton("⏰ مدت زمان", callback_data='duration')],
        [InlineKeyboardButton("✅ تایید و پرداخت", callback_data='confirm')]
    ]
    return InlineKeyboardMarkup(keyboard)

# Volume selection
def volume_menu():
    keyboard = [
        [InlineKeyboardButton("10 GB 📦", callback_data='vol_10')],
        [InlineKeyboardButton("50 GB 📦", callback_data='vol_50')],
        [InlineKeyboardButton("100 GB 📦", callback_data='vol_100')],
        [InlineKeyboardButton("نامحدود ♾️", callback_data='vol_unlimited')]
    ]
    return InlineKeyboardMarkup(keyboard)

# Duration selection
def duration_menu():
    keyboard = [
        [InlineKeyboardButton("1 هفته 📅", callback_data='dur_1week')],
        [InlineKeyboardButton("1 ماه 📅", callback_data='dur_1month')],
        [InlineKeyboardButton("1 سال 📅", callback_data='dur_1year')],
        [InlineKeyboardButton("دائمی ♾️", callback_data='dur_permanent')]
    ]
    return InlineKeyboardMarkup(keyboard)

# Check if user is banned
def is_banned(user_id):
    conn = sqlite3.connect('bot.db')
    c = conn.cursor()
    c.execute('SELECT banned FROM users WHERE user_id = ?', (user_id,))
    result = c.fetchone()
    conn.close()
    return result and result[0] == 1

# Ban user
def ban_user(user_id):
    conn = sqlite3.connect('bot.db')
    c = conn.cursor()
    c.execute('INSERT OR REPLACE INTO users (user_id, banned) VALUES (?, 1)', (user_id,))
    conn.commit()
    conn.close()

# Unban user
def unban_user(user_id):
    conn = sqlite3.connect('bot.db')
    c = conn.cursor()
    c.execute('INSERT OR REPLACE INTO users (user_id, banned) VALUES (?, 0)', (user_id,))
    conn.commit()
    conn.close()

# Save order
def save_order(user_id, volume, duration):
    tracking_code = generate_tracking_code()
    conn = sqlite3.connect('bot.db')
    c = conn.cursor()
    c.execute('INSERT INTO orders (user_id, volume, duration, tracking_code) VALUES (?, ?, ?, ?)',
              (user_id, volume, duration, tracking_code))
    conn.commit()
    conn.close()
    return tracking_code

# Get orders
def get_orders():
    conn = sqlite3.connect('bot.db')
    c = conn.cursor()
    c.execute('SELECT id, user_id, volume, duration, tracking_code, payment_status FROM orders')
    orders = c.fetchall()
    conn.close()
    return orders

# Update order status
def update_order(order_id, status, config=None):
    conn = sqlite3.connect('bot.db')
    c = conn.cursor()
    if config:
        c.execute('UPDATE orders SET payment_status = ?, config = ? WHERE id = ?', (status, config, order_id))
    else:
        c.execute('UPDATE orders SET payment_status = ? WHERE id = ?', (status, order_id))
    conn.commit()
    conn.close()

# Get order by tracking code
def get_order_by_tracking(tracking_code):
    conn = sqlite3.connect('bot.db')
    c = conn.cursor()
    c.execute('SELECT * FROM orders WHERE tracking_code = ?', (tracking_code,))
    order = c.fetchone()
    conn.close()
    return order

# Get order by id
def get_order_by_id(order_id):
    conn = sqlite3.connect('bot.db')
    c = conn.cursor()
    c.execute('SELECT * FROM orders WHERE id = ?', (order_id,))
    order = c.fetchone()
    conn.close()
    return order

# Handlers
async def start(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id
    if is_banned(user_id):
        await update.message.reply_text("🚫 شما بن شده‌اید و نمی‌توانید از ربات استفاده کنید.")
        return
    await update.message.reply_text("🌟 خوش آمدید به ربات فروش کانفیگ!\n\n📊 لطفاً حجم و مدت زمان کانفیگ را انتخاب کنید:", reply_markup=main_menu())

async def button_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    await query.answer()
    user_id = query.from_user.id
    if is_banned(user_id):
        await query.edit_message_text("🚫 شما بن شده‌اید.")
        return

    data = query.data
    if data == 'volume':
        await query.edit_message_text("📊 حجم کانفیگ را انتخاب کنید:", reply_markup=volume_menu())
    elif data == 'duration':
        await query.edit_message_text("⏰ مدت زمان را انتخاب کنید:", reply_markup=duration_menu())
    elif data.startswith('vol_'):
        volume = data.split('_')[1]
        context.user_data['volume'] = volume
        await query.edit_message_text(f"📦 حجم انتخاب شده: {volume} GB\nحالا مدت زمان را انتخاب کنید.", reply_markup=duration_menu())
    elif data.startswith('dur_'):
        duration = data.split('_')[1]
        context.user_data['duration'] = duration
        await query.edit_message_text(f"⏳ مدت زمان انتخاب شده: {duration}\nبرای تایید کلیک کنید.", reply_markup=main_menu())
    elif data == 'confirm':
        volume = context.user_data.get('volume')
        duration = context.user_data.get('duration')
        if not volume or not duration:
            await query.edit_message_text("⚠️ لطفاً ابتدا حجم و مدت زمان را انتخاب کنید.", reply_markup=main_menu())
            return
        tracking_code = save_order(user_id, volume, duration)
        card_number = "425645663868689768786"
        await query.edit_message_text(f"✅ سفارش شما ثبت شد!\n\n🔢 کد پیگیری: `{tracking_code}`\n\n💳 برای پرداخت به شماره کارت زیر واریز کنید:\n`{card_number}`\n\n⏰ 10 دقیقه وقت دارید. اسکرین شات پرداخت را با ریپلای این پیام ارسال کنید.", parse_mode='Markdown')
        # Set a timer for 10 minutes, but for simplicity, we'll handle it manually

    # Admin operations
    elif user_id == ADMIN_USER_ID:
        if data == 'admin_list':
            orders = get_orders()
            if not orders:
                await query.edit_message_text("📭 هیچ سفارشی وجود ندارد.", reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("🔙 بازگشت", callback_data='admin_back')]]))
            else:
                text = "📋 **لیست سفارشات:**\n\n"
                for order in orders:
                    text += f"🆔 {order[0]} | 👤 {order[1]} | 📦 {order[2]}GB | ⏰ {order[3]} | 🔢 {order[4]} | 📊 {order[5]}\n"
                await query.edit_message_text(text, parse_mode='Markdown', reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("🔙 بازگشت", callback_data='admin_back')]]))
        elif data == 'admin_track':
            await query.edit_message_text("🔍 کد پیگیری را وارد کنید:", reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("🔙 بازگشت", callback_data='admin_back')]]))
            context.user_data['awaiting_admin_track'] = True
        elif data == 'admin_ban':
            await query.edit_message_text("🚫 ایدی کاربر را برای بن وارد کنید:", reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("🔙 بازگشت", callback_data='admin_back')]]))
            context.user_data['awaiting_admin_ban'] = True
        elif data == 'admin_unban':
            await query.edit_message_text("✅ ایدی کاربر را برای انبن وارد کنید:", reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("🔙 بازگشت", callback_data='admin_back')]]))
            context.user_data['awaiting_admin_unban'] = True
        elif data == 'admin_approve':
            await query.edit_message_text("✔️ ایدی سفارش را برای تایید وارد کنید:", reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("🔙 بازگشت", callback_data='admin_back')]]))
            context.user_data['awaiting_admin_approve'] = True
        elif data == 'admin_reject':
            await query.edit_message_text("❌ ایدی سفارش را برای رد وارد کنید:", reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("🔙 بازگشت", callback_data='admin_back')]]))
            context.user_data['awaiting_admin_reject'] = True
        elif data == 'admin_back':
            await show_admin_panel(update, context, query)
    else:
        await query.edit_message_text("❌ دسترسی غیرمجاز.")

async def admin_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id
    global ADMIN_USER_ID
    if ADMIN_USER_ID == user_id:
        await show_admin_panel(update, context)
    else:
        await update.message.reply_text("🔐 رمز عبور پنل مدیریت را وارد کنید:")

async def message_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id
    text = update.message.text
    global ADMIN_USER_ID

    if ADMIN_USER_ID and ADMIN_USER_ID == user_id:
        if context.user_data.get('awaiting_admin_track'):
            order = get_order_by_tracking(text)
            if order:
                status = order[5]
                await update.message.reply_text(f"📊 وضعیت سفارش: {status}", reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("🔙 بازگشت به پنل", callback_data='admin_back')]]))
            else:
                await update.message.reply_text("❌ کد پیگیری نامعتبر.", reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("🔙 بازگشت به پنل", callback_data='admin_back')]]))
            context.user_data['awaiting_admin_track'] = False
        elif context.user_data.get('awaiting_admin_ban'):
            try:
                target_user = int(text)
                ban_user(target_user)
                await update.message.reply_text(f"🚫 کاربر {target_user} بن شد.", reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("🔙 بازگشت به پنل", callback_data='admin_back')]]))
            except ValueError:
                await update.message.reply_text("❌ ایدی نامعتبر.", reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("🔙 بازگشت به پنل", callback_data='admin_back')]]))
            context.user_data['awaiting_admin_ban'] = False
        elif context.user_data.get('awaiting_admin_unban'):
            try:
                target_user = int(text)
                unban_user(target_user)
                await update.message.reply_text(f"✅ کاربر {target_user} انبن شد.", reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("🔙 بازگشت به پنل", callback_data='admin_back')]]))
            except ValueError:
                await update.message.reply_text("❌ ایدی نامعتبر.", reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("🔙 بازگشت به پنل", callback_data='admin_back')]]))
            context.user_data['awaiting_admin_unban'] = False
        elif context.user_data.get('awaiting_admin_approve'):
            try:
                order_id = int(text)
                order = get_order_by_id(order_id)
                if not order:
                    await update.message.reply_text("❌ سفارش یافت نشد.", reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("🔙 بازگشت به پنل", callback_data='admin_back')]]))
                    context.user_data['awaiting_admin_approve'] = False
                    return
                update_order(order_id, 'approved', SAMPLE_CONFIG)
                # Send config to user
                conn = sqlite3.connect('bot.db')
                c = conn.cursor()
                c.execute('SELECT user_id FROM orders WHERE id = ?', (order_id,))
                user = c.fetchone()
                conn.close()
                if user:
                    await context.bot.send_message(chat_id=user[0], text=f"🎉 سفارش شما تایید شد!\n\n📄 کانفیگ:\n```\n{SAMPLE_CONFIG}\n```", parse_mode='Markdown')
                await update.message.reply_text(f"✔️ سفارش {order_id} تایید شد.", reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("🔙 بازگشت به پنل", callback_data='admin_back')]]))
            except ValueError:
                await update.message.reply_text("❌ ایدی سفارش نامعتبر.", reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("🔙 بازگشت به پنل", callback_data='admin_back')]]))
            context.user_data['awaiting_admin_approve'] = False
        elif context.user_data.get('awaiting_admin_reject'):
            try:
                order_id = int(text)
                order = get_order_by_id(order_id)
                if not order:
                    await update.message.reply_text("❌ سفارش یافت نشد.", reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("🔙 بازگشت به پنل", callback_data='admin_back')]]))
                    context.user_data['awaiting_admin_reject'] = False
                    return
                update_order(order_id, 'rejected')
                await update.message.reply_text(f"❌ سفارش {order_id} رد شد.", reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("🔙 بازگشت به پنل", callback_data='admin_back')]]))
            except ValueError:
                await update.message.reply_text("❌ ایدی سفارش نامعتبر.", reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("🔙 بازگشت به پنل", callback_data='admin_back')]]))
            context.user_data['awaiting_admin_reject'] = False
        return

    if text == ADMIN_PASSWORD and not ADMIN_USER_ID:
        ADMIN_USER_ID = user_id
        await update.message.reply_text("🔐 ورود به پنل مدیریت موفق! 🎛️", parse_mode='Markdown')
        await show_admin_panel(update, context)
    elif context.user_data.get('awaiting_tracking'):
        order = get_order_by_tracking(text)
        if order:
            status = order[5]
            await update.message.reply_text(f"📊 وضعیت سفارش شما: {status}")
        else:
            await update.message.reply_text("❌ کد پیگیری نامعتبر.")
        context.user_data['awaiting_tracking'] = False
    else:
        # Handle screenshot upload
        if update.message.photo:
            await update.message.reply_text("📸 اسکرین شات دریافت شد. منتظر تایید ادمین باشید. ⏳")

async def show_admin_panel(update: Update, context: ContextTypes.DEFAULT_TYPE, query=None):
    keyboard = [
        [InlineKeyboardButton("📋 لیست سفارشات", callback_data='admin_list')],
        [InlineKeyboardButton("🔍 پیگیری سفارش", callback_data='admin_track')],
        [InlineKeyboardButton("🚫 بن کاربر", callback_data='admin_ban')],
        [InlineKeyboardButton("✅ انبن کاربر", callback_data='admin_unban')],
        [InlineKeyboardButton("✔️ تایید سفارش", callback_data='admin_approve')],
        [InlineKeyboardButton("❌ رد سفارش", callback_data='admin_reject')]
    ]
    reply_markup = InlineKeyboardMarkup(keyboard)
    message = query.message if query else update.message
    await message.reply_text("🎛️ **پنل مدیریت پیشرفته**\n\nانتخاب کنید:", reply_markup=reply_markup, parse_mode='Markdown')

def main():
    print("ربات در حال راه‌اندازی است...")
    bot_id = TOKEN.split(':')[0]
    print(f"ایدی ربات: {bot_id}")
    
    # Get bot username
    response = requests.get(f'https://api.telegram.org/bot{TOKEN}/getMe')
    if response.status_code == 200:
        bot_data = response.json()
        if bot_data['ok']:
            username = bot_data['result']['username']
            print(f"یوزرنیم ربات: @{username}")
        else:
            print("خطا در دریافت اطلاعات ربات")
    else:
        print("خطا در اتصال به API تلگرام")
    
    application = Application.builder().token(TOKEN).build()

    application.add_handler(CommandHandler("start", start))
    application.add_handler(CommandHandler("admin", admin_command))
    application.add_handler(CallbackQueryHandler(button_handler))
    application.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, message_handler))
    application.add_handler(MessageHandler(filters.PHOTO, message_handler))

    print("ربات آماده دریافت پیام‌ها است!")
    application.run_polling()

if __name__ == '__main__':
    main()
