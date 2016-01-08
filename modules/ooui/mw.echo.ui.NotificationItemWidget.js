( function ( mw, $ ) {
	/*global moment:false */
	/**
	 * Notification option widget for echo popup.
	 *
	 * @class
	 * @extends OO.ui.OptionWidget
	 *
	 * @constructor
	 * @param {Object} [config] Configuration object
	 * @cfg {boolean} [markReadWhenSeen=false] This option is marked as read when it is viewed
	 * @cfg {jQuery} [$overlay] A jQuery element functioning as an overlay
	 *  for popups.
	 * @cfg {boolean} [bundle=false] This notification item is part of a bundle.
	 */
	mw.echo.ui.NotificationItemWidget = function MwEchoUiNotificationItemWidget( model, config ) {
		var i, secondaryUrls, urlObj, linkButton, $icon,
			$content = $( '<div>' ).addClass( 'mw-echo-ui-notificationItemWidget-content' ),
			$message = $( '<div>' ).addClass( 'mw-echo-ui-notificationItemWidget-content-message' ),
			widget = this;

		config = config || {};

		// Parent constructor
		mw.echo.ui.NotificationItemWidget.parent.call( this, $.extend( { data: model.getId() }, config ) );

		this.model = model;
		this.$overlay = config.$overlay || this.$element;
		this.bundle = !!config.bundle;

		this.$actions = $( '<div>' )
			.addClass( 'mw-echo-ui-notificationItemWidget-content-actions' );

		// Mark unread
		this.markAsReadButton = new OO.ui.ButtonWidget( {
			icon: 'close',
			framed: false,
			classes: [ 'mw-echo-ui-notificationItemWidget-markAsReadButton' ]
		} );

		this.toggleRead( this.model.isRead() );
		this.toggleSeen( this.model.isSeen() );

		this.markReadWhenSeen = !!config.markReadWhenSeen;
		this.markAsReadButton.toggle( !this.markReadWhenSeen && !this.model.isRead() );

		// Events
		this.markAsReadButton.connect( this, { click: 'onMarkAsReadButtonClick' } );
		this.model.connect( this, {
			seen: 'toggleSeen',
			read: 'toggleRead'
		} );

		// Icon
		if ( this.model.getIconURL() ) {
			$icon = $( '<div>' )
				.addClass( 'mw-echo-ui-notificationItemWidget-icon' )
				.append( $( '<img>' ).attr( 'src', this.model.getIconURL() ) );
		}

		// Content
		$message.append(
			$( '<div>' )
				.addClass( 'mw-echo-ui-notificationItemWidget-content-message-header' )
				.append( this.model.getContentHeader() )
		);
		if ( !this.bundle && this.model.getContentBody() ) {
			$message.append(
				$( '<div>' )
					.addClass( 'mw-echo-ui-notificationItemWidget-content-message-body' )
					.append( this.model.getContentBody() )
			);
		}

		// Actions menu
		this.actionsButtonSelectWidget = new OO.ui.ButtonSelectWidget( {
			classes: [ 'mw-echo-ui-notificationItemWidget-content-actions-buttons' ]
		} );

		// Popup menu
		this.menuPopupButtonWidget = new mw.echo.ui.ActionMenuPopupWidget( {
			framed: false,
			icon: 'ellipsis',
			$overlay: this.$overlay,
			menuWidth: 200,
			classes: [ 'mw-echo-ui-notificationItemWidget-content-actions-menu' ]
		} );

		// Timestamp
		this.timestampWidget = new OO.ui.LabelWidget( {
			classes: [ 'mw-echo-ui-notificationItemWidget-content-actions-timestamp' ],
			label: moment.utc( this.model.getTimestamp(), 'YYYYMMDDHHmmss' ).fromNow()
		} );

		// Build the actions line
		this.$actions.append(
			this.actionsButtonSelectWidget.$element,
			this.menuPopupButtonWidget.$element,
			this.timestampWidget.$element
		);

		// Actions
		secondaryUrls = this.model.getSecondaryUrls();
		for ( i = 0; i < secondaryUrls.length; i++ ) {
			urlObj = secondaryUrls[ i ];

			linkButton = new OO.ui.ButtonOptionWidget( {
				icon: urlObj.icon,
				framed: false,
				label: urlObj.label,
				classes: [ 'mw-echo-ui-notificationItemWidget-content-actions-button' ]
			} );
			if ( urlObj.url ) {
				linkButton.$element.find( 'a.oo-ui-buttonElement-button' )
					// HACK: We need to use ButtonOptionWidgets because both SelectWidgets expect an OptionWidget
					// However, the optionwidgets do not support href for buttons, because they listen to 'choose'
					// and 'select' events and do their own thing.
					// We could solve this by reimplementing/extending the ActionMenuPopupWidget *and* the SelectWidget
					// or we can do the simpler thing, which is to wrap our buttons with a link.
					// Since we do the latter in the notifications anyways (see below) this seemed to be the
					// best course of action.
					.attr( 'href', urlObj.url );
			}

			if ( !this.bundle && urlObj.prioritized !== undefined ) {
				this.actionsButtonSelectWidget.addItems( [ linkButton ] );
			} else {
				this.menuPopupButtonWidget.getMenu().addItems( [ linkButton ] );
			}
		}
		this.menuPopupButtonWidget.toggle( !this.menuPopupButtonWidget.getMenu().isEmpty() );

		this.$element
			.addClass( 'mw-echo-ui-notificationItemWidget mw-echo-ui-notificationItemWidget-' + this.model.getType() )
			.toggleClass( 'mw-echo-ui-notificationItemWidget-initiallyUnseen', !this.model.isSeen() && !this.bundle )
			.toggleClass( 'mw-echo-ui-notificationItemWidget-bundle', this.bundle )
			.append(
				$icon,
				$content.append(
					this.markAsReadButton.$element,
					$message,
					this.$actions
				)
			);

		if ( this.model.getPrimaryUrl() ) {
			this.$element.contents()
				.wrapAll(
					// HACK: Wrap the entire item with a link that takes
					// the user to the primary url. This is not perfect,
					// but it makes the behavior native to the browser rather
					// than us listening to click events and opening new
					// windows.
					$( '<a>' )
						.addClass( 'mw-echo-ui-notificationItemWidget-linkWrapper' )
						.attr( 'href', this.model.getPrimaryUrl() )
						.on( 'click', function () {
							// Log notification click

							// TODO: In order to properly log a click of an item that
							// is part of a bundled cross-wiki notification, we will
							// need to add 'source' to the logging schema. Otherwise,
							// the logger will log item ID as if it is local, which
							// is wrong.
							mw.echo.logger.logInteraction(
								mw.echo.Logger.static.actions.notificationClick,
								mw.echo.Logger.static.context.popup,
								widget.getModel().getId(),
								widget.getModel().getCategory()
							);
						} )
				);
		}

		// HACK: We have to remove the built-in label. When this
		// widget is switched to a standalone widget rather than
		// an OptionWidget we can get rid of this
		this.$label.detach();
	};

	/* Initialization */

	OO.inheritClass( mw.echo.ui.NotificationItemWidget, OO.ui.OptionWidget );

	/* Static properties */

	mw.echo.ui.NotificationItemWidget.static.pressable = false;
	mw.echo.ui.NotificationItemWidget.static.selectable = false;

	/* Events */

	/**
	 * @event markAsRead
	 *
	 * Mark this notification as read
	 */

	/* Methods */

	/**
	 * Respond to mark as read button click
	 */
	mw.echo.ui.NotificationItemWidget.prototype.onMarkAsReadButtonClick = function () {
		this.model.toggleRead( true );
	};

	/**
	 * Reset the status of the notification without touching its user-controlled status.
	 * For one, remove 'initiallyUnseen' which exists only for the animation to work.
	 * This is called when new notifications are added to the parent widget, having to
	 * reset the 'unseen' status from the old ones.
	 */
	mw.echo.ui.NotificationItemWidget.prototype.reset = function () {
		this.$element.removeClass( 'mw-echo-ui-notificationItemWidget-initiallyUnseen' );
	};

	/**
	 * Toggle the read state of the widget
	 *
	 * @param {boolean} [read] The current read state. If not given, the state will
	 *  become the opposite of its current state.
	 */
	mw.echo.ui.NotificationItemWidget.prototype.toggleRead = function ( read ) {
		this.read = read !== undefined ? read : !this.read;

		this.$element.toggleClass( 'mw-echo-ui-notificationItemWidget-unread', !this.read );
		this.markAsReadButton.toggle( !this.read );
	};

	/**
	 * Toggle the seen state of the widget
	 *
	 * @param {boolean} [seen] The current seen state. If not given, the state will
	 *  become the opposite of its current state.
	 */
	mw.echo.ui.NotificationItemWidget.prototype.toggleSeen = function ( seen ) {
		this.seen = seen !== undefined ? seen : !this.seen;

		this.$element
			.toggleClass( 'mw-echo-ui-notificationItemWidget-unseen', !this.seen );
	};

	/**
	 * Get the notification link
	 *
	 * @return {string} Notification link
	 */
	mw.echo.ui.NotificationItemWidget.prototype.getModel = function () {
		return this.model;
	};

	/**
	 * Get the notification link
	 *
	 * @return {string} Notification link
	 */
	mw.echo.ui.NotificationItemWidget.prototype.getPrimaryUrl = function () {
		return this.model.getPrimaryUrl();
	};

	/**
	 * Disconnect events when widget is destroyed.
	 */
	mw.echo.ui.NotificationItemWidget.prototype.destroy = function () {
		this.model.disconnect( this );
	};

} )( mediaWiki, jQuery );