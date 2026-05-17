import sqlite3
import random
import string
from telegram import (
    Update,
    InlineKeyboardButton,
    InlineKeyboardMarkup,
    KeyboardButton,
    ReplyKeyboardMarkup
)

from telegram.ext import (
    Application,
    CommandHandler,
    CallbackQueryHandler,
    MessageHandler,
    ContextTypes,
    filters
)

TOKEN = "YOUR_NEW_BOT_TOKEN"
ADMIN_PASSWORD = "1390"
ADMIN_ID = 123456789
FORCE_CHANNEL = "@h4x_top"
CARD_NUMBER = "6037991234567890"


# =========================
# DATABASE
# =========================

def init_db():
    conn = sqlite3.connect("bot.db")
    c = conn.cursor()

    c.execute('''
    CREATE TABLE IF NOT EXISTS users (
        user_id INTEGER PRIMARY KEY,
        phone TEXT,
        invited_by INTEGER,
        referrals INTEGER DEFAULT 0,
        verified INTEGER DEFAULT 0,
        banned INTEGER DEFAULT 0
    )
    ''')

    c.execute('''
    CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        volume TEXT,
        duration TEXT,
        tracking_code TEXT,
        amount TEXT,
        screenshot TEXT,
        payment_status TEXT DEFAULT 'pending',
        config TEXT
    )
    ''')

    conn.commit()
    conn.close()


init_db()


# =========================
# HELPERS
# =========================

def connect_db():
    return sqlite3.connect("bot.db")


def generate_tracking_code():
    return ''.join(random.choices(string.ascii_uppercase + string.digits, k=10))


def generate_random_amount():
    base = 150000
    random_part = random.randint(1000, 9999)
    return f"{base}.{random_part}"


async def is_member(bot, user_id):
    try:
        member = await bot.get_chat_member(FORCE_CHANNEL, user_id)
        return member.status in ['member', 'administrator', 'creator']
    except:
        return False


async def check_access(update: Update):
    user_id = update.effective_user.id

    if not await is_member(update.get_bot(), user_id):
        keyboard = [
            [InlineKeyboardButton("📢 عضویت در کانال", url=f"https://t.me/{FORCE_CHANNEL.replace('@', '')}")],
            [InlineKeyboardButton("✅ بررسی عضویت", callback_data="check_join")]
        ]

        await update.effective_message.reply_text(
            "🚫 برای استفاده از ربات باید ابتدا عضو کانال شوید.",
            reply_markup=InlineKeyboardMarkup(keyboard)
        )

        return False

    conn = connect_db()
    c = conn.cursor()

    c.execute("SELECT verified FROM users WHERE user_id=?", (user_id,))
    row = c.fetchone()

    conn.close()

    if not row or row[0] == 0:
        button = KeyboardButton("📱 تایید شماره", request_contact=True)

        await update.effective_message.reply_text(
            "📱 برای ادامه باید شماره تلفن خود را تایید کنید.",
            reply_markup=ReplyKeyboardMarkup([[button]], resize_keyboard=True, one_time_keyboard=True)
        )

        return False

    return True


# =========================
# MENUS
# =========================

def main_menu():
    keyboard = [
        [InlineKeyboardButton("📦 خرید سرویس", callback_data="buy")],
        [InlineKeyboardButton("🎁 تست رایگان", callback_data="free_test")],
        [InlineKeyboardButton("📊 پیگیری سفارش", callback_data="track")]
    ]

    return InlineKeyboardMarkup(keyboard)



def volume_menu():
    keyboard = [
        [InlineKeyboardButton("10GB", callback_data="vol_10")],
        [InlineKeyboardButton("50GB", callback_data="vol_50")],
        [InlineKeyboardButton("100GB", callback_data="vol_100")],
        [InlineKeyboardButton("Unlimited", callback_data="vol_unlimited")]
    ]

    return InlineKeyboardMarkup(keyboard)



def duration_menu():
    keyboard = [
        [InlineKeyboardButton("1 Week", callback_data="dur_1week")],
        [InlineKeyboardButton("1 Month", callback_data="dur_1month")],
        [InlineKeyboardButton("1 Year", callback_data="dur_1year")],
        [InlineKeyboardButton("Permanent", callback_data="dur_perm")]
    ]

    return InlineKeyboardMarkup(keyboard)


# =========================
# DATABASE ACTIONS
# =========================

def save_user(user_id, phone=None, invited_by=None):
    conn = connect_db()
    c = conn.cursor()

    c.execute('''
    INSERT OR IGNORE INTO users(user_id, invited_by)
    VALUES(?, ?)
    ''', (user_id, invited_by))

    if phone:
        c.execute('''
        UPDATE users
        SET phone=?, verified=1
        WHERE user_id=?
        ''', (phone, user_id))

    conn.commit()
    conn.close()



def add_referral(inviter_id):
    conn = connect_db()
    c = conn.cursor()

    c.execute('''
    UPDATE users
    SET referrals = referrals + 1
    WHERE user_id=?
    ''', (inviter_id,))

    conn.commit()
    conn.close()



def get_referrals(user_id):
    conn = connect_db()
    c = conn.cursor()

    c.execute("SELECT referrals FROM users WHERE user_id=?", (user_id,))
    row = c.fetchone()

    conn.close()

    if row:
        return row[0]

    return 0



def save_order(user_id, volume, duration, amount):
    tracking_code = generate_tracking_code()

    conn = connect_db()
    c = conn.cursor()

    c.execute('''
    INSERT INTO orders(
        user_id,
        volume,
        duration,
        tracking_code,
        amount
    )
    VALUES(?,?,?,?,?)
    ''', (user_id, volume, duration, tracking_code, amount))

    conn.commit()
    conn.close()

    return tracking_code



def save_screenshot(user_id, file_id):
    conn = connect_db()
    c = conn.cursor()

    c.execute('''
    UPDATE orders
    SET screenshot=?
    WHERE user_id=?
    ORDER BY id DESC
    LIMIT 1
    ''', (file_id, user_id))

    conn.commit()
    conn.close()



def get_orders():
    conn = connect_db()
    c = conn.cursor()

    c.execute("SELECT * FROM orders ORDER BY id DESC")
    rows = c.fetchall()

    conn.close()

    return rows



def update_order(order_id, status, config=None):
    conn = connect_db()
    c = conn.cursor()

    c.execute('''
    UPDATE orders
    SET payment_status=?, config=?
    WHERE id=?
    ''', (status, config, order_id))

    conn.commit()
    conn.close()


# =========================
# START
# =========================

async def start(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user = update.effective_user

    invited_by = None

    if context.args:
        try:
            invited_by = int(context.args[0])
        except:
            pass

    save_user(user.id, invited_by=invited_by)

    if not await check_access(update):
        return

    await update.message.reply_text(
        "🌟 به ربات فروش VPN خوش اومدی",
        reply_markup=main_menu()
    )


# =========================
# CONTACT VERIFY
# =========================

async def contact_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    contact = update.message.contact
    user_id = update.effective_user.id

    if contact.user_id != user_id:
        await update.message.reply_text("❌ فقط شماره خودت را ارسال کن")
        return

    save_user(user_id, phone=contact.phone_number)

    conn = connect_db()
    c = conn.cursor()

    c.execute("SELECT invited_by FROM users WHERE user_id=?", (user_id,))
    row = c.fetchone()

    conn.close()

    if row and row[0]:
        add_referral(row[0])

    await update.message.reply_text(
        "✅ شماره تایید شد. حالا میتونی از ربات استفاده کنی.",
        reply_markup=main_menu()
    )


# =========================
# BUTTONS
# =========================

async def buttons(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    await query.answer()

    user_id = query.from_user.id

    if query.data == "check_join":
        if await is_member(query.bot, user_id):
            await query.message.reply_text(
                "✅ عضو شدی. حالا شماره تلفنتو تایید کن."
            )
        else:
            await query.answer("❌ هنوز عضو نشدی", show_alert=True)

    elif query.data == "buy":
        await query.message.reply_text(
            "📦 حجم را انتخاب کن",
            reply_markup=volume_menu()
        )

    elif query.data.startswith("vol_"):
        context.user_data['volume'] = query.data.replace("vol_", "")

        await query.message.reply_text(
            "⏰ زمان را انتخاب کن",
            reply_markup=duration_menu()
        )

    elif query.data.startswith("dur_"):
        duration = query.data.replace("dur_", "")
        volume = context.user_data.get("volume")

        amount = generate_random_amount()

        tracking = save_order(
            user_id,
            volume,
            duration,
            amount
        )

        text = f'''
✅ سفارش ثبت شد

🔢 کد پیگیری:
{tracking}

💳 شماره کارت:
{CARD_NUMBER}

💰 مبلغ دقیق:
{amount}

⚠️ دقیقا همین مبلغ را واریز کن.
سپس اسکرین شات را همینجا ارسال کن.
'''

        await query.message.reply_text(text)

    elif query.data == "free_test":
        referrals = get_referrals(user_id)

        referral_link = f"https://t.me/{context.bot.username}?start={user_id}"

        text = f'''
🎁 دریافت تست رایگان 50MB

برای دریافت تست رایگان باید حداقل 10 نفر را با لینک اختصاصی خودت وارد ربات کنی.

⚠️ کاربران باید:
- عضو کانال شوند
- شماره خود را تایید کنند
- کامل وارد ربات شوند

👥 تعداد رفرال فعلی: {referrals}/10

🔗 لینک دعوت اختصاصی:
{referral_link}
'''

        if referrals >= 10:
            text += "\n\n✅ تبریک! تست رایگان فعال شد."

        await query.message.reply_text(text)

    elif query.data == "admin_orders":
        if user_id != ADMIN_ID:
            return

        orders = get_orders()

        if not orders:
            await query.message.reply_text("سفارشی وجود ندارد")
            return

        for order in orders:
            text = f'''
🆔 سفارش: {order[0]}
👤 کاربر: {order[1]}
📦 حجم: {order[2]}
⏰ زمان: {order[3]}
💰 مبلغ: {order[5]}
📊 وضعیت: {order[7]}
'''

            if order[6]:
                await query.message.reply_photo(
                    photo=order[6],
                    caption=text,
                    reply_markup=InlineKeyboardMarkup([
                        [
                            InlineKeyboardButton(
                                "✅ تایید",
                                callback_data=f"approve_{order[0]}"
                            ),
                            InlineKeyboardButton(
                                "❌ رد",
                                callback_data=f"reject_{order[0]}"
                            )
                        ]
                    ])
                )
            else:
                await query.message.reply_text(text)

    elif query.data.startswith("approve_"):
        if user_id != ADMIN_ID:
            return

        order_id = int(query.data.split("_")[1])

        context.user_data['send_to_order'] = order_id
        context.user_data['approve_mode'] = True

        await query.message.reply_text(
            "✍️ حالا پیام دلخواهت را بفرست تا برای کاربر ارسال شود.\n\nمثلا کانفیگ یا متن تایید"
        )

    elif query.data.startswith("reject_"):
        if user_id != ADMIN_ID:
            return

        order_id = int(query.data.split("_")[1])

        context.user_data['send_to_order'] = order_id
        context.user_data['reject_mode'] = True

        await query.message.reply_text(
            "✍️ متن رد سفارش را ارسال کن"
        )


# =========================
# ADMIN PANEL
# =========================

async def admin(update: Update, context: ContextTypes.DEFAULT_TYPE):
    if update.effective_user.id != ADMIN_ID:
        await update.message.reply_text("❌ دسترسی نداری")
        return

    keyboard = [
        [InlineKeyboardButton("📦 سفارشات", callback_data="admin_orders")]
    ]

    await update.message.reply_text(
        "🎛 پنل مدیریت",
        reply_markup=InlineKeyboardMarkup(keyboard)
    )


# =========================
# MESSAGE HANDLER
# =========================

async def messages(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id

    if update.message.photo:
        photo = update.message.photo[-1].file_id

        save_screenshot(user_id, photo)

        await update.message.reply_text(
            "✅ اسکرین شات ثبت شد. منتظر تایید ادمین باشید."
        )

        await context.bot.send_message(
            ADMIN_ID,
            f"📥 اسکرین شات جدید از کاربر {user_id}"
        )

        return

    if user_id == ADMIN_ID:

        if context.user_data.get('approve_mode'):
            order_id = context.user_data['send_to_order']

            conn = connect_db()
            c = conn.cursor()

            c.execute("SELECT user_id FROM orders WHERE id=?", (order_id,))
            row = c.fetchone()

            conn.close()

            if row:
                target_user = row[0]

                update_order(order_id, "approved", update.message.text)

                await context.bot.send_message(
                    target_user,
                    f"✅ سفارش شما تایید شد\n\n{update.message.text}"
                )

                await update.message.reply_text("✅ ارسال شد")

            context.user_data['approve_mode'] = False

        elif context.user_data.get('reject_mode'):
            order_id = context.user_data['send_to_order']

            conn = connect_db()
            c = conn.cursor()

            c.execute("SELECT user_id FROM orders WHERE id=?", (order_id,))
            row = c.fetchone()

            conn.close()

            if row:
                target_user = row[0]

                update_order(order_id, "rejected")

                await context.bot.send_message(
                    target_user,
                    f"❌ سفارش شما رد شد\n\n{update.message.text}"
                )

                await update.message.reply_text("❌ پیام رد ارسال شد")

            context.user_data['reject_mode'] = False


# =========================
# MAIN
# =========================


def main():
    app = Application.builder().token(TOKEN).build()

    app.add_handler(CommandHandler("start", start))
    app.add_handler(CommandHandler("admin", admin))

    app.add_handler(CallbackQueryHandler(buttons))

    app.add_handler(MessageHandler(filters.CONTACT, contact_handler))

    app.add_handler(MessageHandler(filters.PHOTO, messages))

    app.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, messages))

    print("BOT STARTED")

    app.run_polling()


if __name__ == "__main__":
    main()
